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

    public function definition(): array
    {
        $department = Department::inRandomOrder()->first() ?? Department::factory();
        $position = Position::where('department_id', $department->id)->inRandomOrder()->first();

        $id = str_pad((string) static::$sequence++, 4, '0', STR_PAD_LEFT);

        return [
            'employee_id' => "EMP-{$id}",
            'full_name' => $this->faker->name(),
            'company_id' => $department->company_id,
            'department_id' => $department->id,
            'position_id' => $position?->id,
            'status' => 'active',
            'join_date' => $this->faker->dateTimeBetween('-5 years', '-1 month'),
            'phone' => $this->faker->numerify('08##########'),
        ];
    }
}
