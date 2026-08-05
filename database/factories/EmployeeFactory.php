<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    protected static int $sequence = 1;

    /**
     * v1.10.6 fix: was using $this->faker->... -- that property no longer
     * exists on the base Factory class as of Laravel 9+ (this project is
     * on ^12.0), so it silently resolved to null and blew up on the first
     * method call. Switched to the fake() helper, matching the convention
     * EmployeeSeeder already uses elsewhere. Unrelated to the
     * Multi-Company migration -- this bug simply never ran before,
     * because Employee::factory() itself was broken (missing HasFactory)
     * until the previous fix.
     */
    public function definition(): array
    {
        $department = Department::inRandomOrder()->first() ?? Department::factory();
        $position = Position::where('department_id', $department->id)->inRandomOrder()->first();

        $id = str_pad((string) static::$sequence++, 4, '0', STR_PAD_LEFT);

        return [
            'employee_id' => "EMP-{$id}",
            'full_name' => fake()->name(),
            'company_id' => $department->company_id,
            'department_id' => $department->id,
            'position_id' => $position?->id,
            'status' => 'active',
            'join_date' => fake()->dateTimeBetween('-5 years', '-1 month'),
            'phone' => fake()->numerify('08##########'),
        ];
    }
}
