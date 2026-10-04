<?php

namespace App\Http\Requests;

use App\Models\Package;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * v2.93.0 -- Master Admin > Registrations > Grant Complimentary Access.
 *
 * NOTHING COMMERCIAL IS TAKEN ON TRUST FROM THE BROWSER. The form submits
 * three things and only three: which plan, how many months, and why. Every
 * other fact about the resulting subscription -- the tenant it belongs to,
 * the billing mode, the status, the entitlements, the start date -- is
 * decided server-side by TenantProvisioningService.
 *
 * In particular there is DELIBERATELY no `billing_mode`, no `status`, no
 * `tenant_id`, no `ends_at` and no `amount` field here. A request that
 * could name its own billing mode would be a request that could mark
 * itself paid; a request that could name its own end date would be a
 * request that could grant itself a decade. The route this validates for
 * does exactly one thing, so it accepts only the three facts that thing
 * actually needs.
 *
 * `authorize()` checks `isPlatformAdmin()` as well as the route's
 * `role:platform_admin` middleware, which is the belt-and-braces pattern
 * every other Platform request in this codebase follows. Granting free
 * access to a customer organization is the single most sensitive action on
 * this surface, so it is the last place to rely on one check.
 */
class GrantComplimentaryAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPlatformAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            /*
             * An ACTIVE package only. An operator must not be able to put a
             * customer on a plan that has been retired from the catalogue
             * (Enterprise, since v2.84.0) by posting its id -- the select
             * will not offer it, and this is what makes that true rather
             * than merely likely.
             *
             * `is_public` is NOT required: a private-but-active plan is a
             * legitimate thing to grant, and that distinction is about who
             * may BUY a plan on the website, not about what an operator may
             * assign.
             */
            'package_id' => [
                'required',
                Rule::exists('packages', 'id')->where(fn ($q) => $q->where('is_active', true)),
            ],

            /*
             * A closed allow-list from config, not a free integer. An
             * operator typing 999 into a number field should not be able to
             * grant an eighty-three-year free subscription, and a typo in a
             * date picker should not be able to either.
             */
            'months' => ['required', 'integer', Rule::in(config('saas.complimentary_durations'))],

            /*
             * Required, and required to be substantial. The reason is the
             * only part of this record that explains WHY a customer is not
             * being charged, and it is what an audit a year from now
             * actually reads. A blank or one-word reason would make the
             * audit log technically complete and practically useless.
             */
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.min' => 'Alasan pemberian akses gratis harus dijelaskan, minimal 10 karakter.',
            'months.in' => 'Durasi tidak berada dalam pilihan yang diizinkan.',
            'package_id.exists' => 'Paket tersebut tidak tersedia untuk diberikan.',
        ];
    }

    /** The validated plan, re-read from the database rather than from the request. */
    public function package(): Package
    {
        return Package::findOrFail($this->validated()['package_id']);
    }
}
