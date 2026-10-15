<?php

namespace Database\Seeders;

use App\LocationStatus;
use App\LocationType;
use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use App\SystemRole;
use App\UserStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class DemoDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new LogicException('Demo data cannot be seeded in production.');
        }

        $this->call([
            RolePermissionSeeder::class,
            DemoCatalogSeeder::class,
        ]);

        $business = Business::query()->with('owner')->first();

        if ($business === null) {
            $this->command->warn('Create the initial business and owner before seeding demo data.');

            return;
        }

        DB::transaction(function () use ($business): void {
            $locations = $this->seedLocations($business);
            $users = $this->seedUsers();

            $allLocationIds = $locations->pluck('id')->all();
            $business->owner->locations()->syncWithoutDetaching($allLocationIds);
            $users['admin']->locations()->syncWithoutDetaching($allLocationIds);
            $users['manager']->locations()->syncWithoutDetaching($allLocationIds);
            $users['cashier']->locations()->syncWithoutDetaching([
                $locations['main']->id,
                $locations['branch']->id,
            ]);
            $users['technician']->locations()->syncWithoutDetaching([
                $locations['repair']->id,
            ]);
        });
    }

    /** @return Collection<string, Location> */
    private function seedLocations(Business $business): Collection
    {
        $main = $business->locations()->where('is_main', true)->first();

        if ($main === null) {
            $main = $business->locations()->firstOrNew(['code' => 'MAIN']);
            $main->fill([
                'name' => $business->name.' Main Shop',
                'type' => LocationType::Shop,
                'phone' => $business->phone,
                'email' => $business->email,
                'address' => $business->address,
                'city' => $business->city,
                'status' => LocationStatus::Active,
            ]);
            $main->forceFill(['is_main' => true])->save();
        }

        $warehouse = $business->locations()->firstOrCreate(
            ['code' => 'WH-01'],
            [
                'name' => 'Central Warehouse',
                'type' => LocationType::Warehouse,
                'phone' => '0712000101',
                'email' => 'warehouse@nalakamobile.test',
                'address' => '25 Industrial Road',
                'city' => 'Kurunegala',
                'status' => LocationStatus::Active,
            ],
        );
        $repair = $business->locations()->firstOrCreate(
            ['code' => 'REP-01'],
            [
                'name' => 'Mobile Repair Centre',
                'type' => LocationType::RepairCentre,
                'phone' => '0712000102',
                'email' => 'repairs@nalakamobile.test',
                'address' => '48 Service Lane',
                'city' => 'Kurunegala',
                'status' => LocationStatus::Active,
            ],
        );
        $branch = $business->locations()->firstOrCreate(
            ['code' => 'KDY-01'],
            [
                'name' => 'Kandy City Branch',
                'type' => LocationType::Shop,
                'phone' => '0712000103',
                'email' => 'kandy@nalakamobile.test',
                'address' => '120 Peradeniya Road',
                'city' => 'Kandy',
                'status' => LocationStatus::Active,
            ],
        );

        return collect([
            'main' => $main,
            'warehouse' => $warehouse,
            'repair' => $repair,
            'branch' => $branch,
        ]);
    }

    /** @return array{admin: User, manager: User, cashier: User, technician: User} */
    private function seedUsers(): array
    {
        $definitions = [
            'admin' => ['name' => 'Demo Administrator', 'email' => 'admin@nalakamobile.test', 'phone' => '0713000101', 'role' => SystemRole::Admin],
            'manager' => ['name' => 'Demo Store Manager', 'email' => 'manager@nalakamobile.test', 'phone' => '0713000102', 'role' => SystemRole::Manager],
            'cashier' => ['name' => 'Demo Cashier', 'email' => 'cashier@nalakamobile.test', 'phone' => '0713000103', 'role' => SystemRole::Cashier],
            'technician' => ['name' => 'Demo Technician', 'email' => 'technician@nalakamobile.test', 'phone' => '0713000104', 'role' => SystemRole::Technician],
        ];
        $users = [];

        foreach ($definitions as $key => $definition) {
            $user = User::query()->firstOrCreate(
                ['email' => $definition['email']],
                [
                    'name' => $definition['name'],
                    'phone' => $definition['phone'],
                    'password' => 'NalakaDemo#2026',
                    'status' => UserStatus::Active,
                ],
            );

            if (! $user->hasRole($definition['role']->value)) {
                $user->assignRole($definition['role']->value);
            }

            $users[$key] = $user;
        }

        return $users;
    }
}
