<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * v2.108.0 -- THE RULES THAT MAKE BULK DELETION SAFE, IN ONE PLACE.
 *
 * Removing one employee needs no ceremony: the record is named on screen
 * and the person clicking is looking at it. Removing four hundred is a
 * different act, and every rule here exists because the obvious
 * implementation of it is unsafe.
 *
 * WHY THE IDS ARE RESOLVED HERE AND NOT IN THE CONTROLLER. The tenant
 * boundary is a validation question, not a cleanup step: if any id does
 * not belong to the caller's own tenant, there is nothing to discuss and
 * no partial action to take. Resolving it in `rules()`/`after()` means
 * the request never reaches the controller in a state where a partial
 * delete is even expressible.
 *
 * ALL OR NOTHING, and deliberately not "delete what you can". A request
 * that silently drops the ids it was not allowed to touch answers the
 * question "does employee 4312 exist in another tenant?" by how many rows
 * it deleted. It also leaves the customer unable to say what went.
 */
class BulkDestroyEmployeesRequest extends FormRequest
{
    /**
     * The upper bound on one request. High enough for the real case this
     * exists for -- undoing a mis-imported file, which is hundreds of
     * rows -- and low enough that a runaway client cannot ask to delete
     * an entire workforce in a single call.
     */
    public const MAX_PER_REQUEST = 500;

    /**
     * The word the user types. Checked on the server, because a dialog
     * the client could skip is not consent. Indonesian, because it is
     * typed by the customer's own administrator and the help text next
     * to it is Indonesian too.
     */
    public const CONFIRMATION = 'HAPUS';

    /** @var \Illuminate\Database\Eloquent\Collection<int, Employee>|null */
    private $resolved = null;

    public function authorize(): bool
    {
        // Same authority that deletes one employee. `deleteAny` is its own
        // policy method rather than `delete` on an arbitrary record,
        // because the action here is not "this employee" -- it is the
        // capability itself.
        return $this->user()?->can('deleteAny', Employee::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_PER_REQUEST],
            'ids.*' => ['required', 'integer', 'distinct'],
            /*
             * The count the human agreed to, sent back so the server can
             * check it against what it actually resolved. This is what
             * catches a selection made on a page that has since changed:
             * the user approved "delete 12", and if the request would
             * remove any other number, it is not the act they approved.
             */
            'expected_count' => ['required', 'integer', 'min:1'],
            'confirmation' => ['required', 'string', 'in:'.self::CONFIRMATION],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Pilih minimal satu karyawan untuk dihapus.',
            'ids.max' => 'Maksimal '.self::MAX_PER_REQUEST.' karyawan dapat dihapus dalam satu permintaan.',
            'ids.*.distinct' => 'Ada karyawan yang terpilih lebih dari satu kali.',
            'confirmation.in' => 'Ketik '.self::CONFIRMATION.' untuk memastikan penghapusan ini disengaja.',
        ];
    }

    /**
     * The tenant boundary and the agreed count, checked together so that
     * a failure of either refuses the whole request.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $ids = $this->integerIds();
                $employees = $this->resolveEmployees($ids);

                /*
                 * Fail-closed, and identically for every reason an id
                 * might not have resolved: it belongs to another tenant,
                 * it never existed, or it is already deleted. The message
                 * does not distinguish them, because telling the caller
                 * WHICH of those it was is exactly the cross-tenant
                 * oracle this rule exists to close.
                 */
                if ($employees->count() !== count($ids)) {
                    $validator->errors()->add(
                        'ids',
                        'Sebagian karyawan yang dipilih tidak dapat dihapus karena sudah tidak ada atau bukan milik perusahaan Anda. '
                        .'Tidak ada data yang dihapus. Muat ulang halaman lalu coba lagi.'
                    );

                    return;
                }

                if ((int) $this->input('expected_count') !== $employees->count()) {
                    $validator->errors()->add(
                        'expected_count',
                        'Jumlah karyawan yang dipilih sudah berubah sejak Anda menekan hapus. '
                        .'Tidak ada data yang dihapus. Muat ulang halaman lalu periksa kembali.'
                    );
                }
            },
        ];
    }

    /**
     * The employees this request may delete: already resolved, already
     * inside the caller's tenant, and guaranteed to be the complete set
     * the request asked for (validation refuses the request otherwise).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Employee>
     */
    public function employees()
    {
        return $this->resolved ?? $this->resolveEmployees($this->integerIds());
    }

    /** @return list<int> */
    private function integerIds(): array
    {
        return array_values(array_map('intval', (array) $this->input('ids', [])));
    }

    /**
     * @param  list<int>  $ids
     * @return \Illuminate\Database\Eloquent\Collection<int, Employee>
     */
    private function resolveEmployees(array $ids)
    {
        /*
         * `Company::query()` passes through TenantScope, which is the
         * same authority every guarded read in this codebase uses, and
         * `Employee` carries no tenant scope of its own (see
         * EmployeeController::index()'s note). So the company id list is
         * the boundary, and it is applied as the BASE filter rather than
         * as a check afterwards.
         */
        return $this->resolved = Employee::query()
            ->whereIn('id', $ids)
            ->whereIn('company_id', Company::query()->pluck('id'))
            ->get();
    }
}
