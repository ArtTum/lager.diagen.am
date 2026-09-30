<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\LagerAccessSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateInitialAdmin extends Command
{
    protected $signature = 'lager:admin:create';

    protected $description = 'Create the first administrator for a new Lager installation';

    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->components->error('Համակարգում արդեն կան օգտատերեր․ առաջին ադմինի հրամանը հասանելի է միայն դատարկ համակարգում։');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Ադմինիստրատորի անունը'));
        $email = mb_strtolower(trim((string) $this->ask('Մուտքանուն / էլ. փոստ')));
        $password = (string) $this->secret('Գաղտնաբառ (առնվազն 14 նիշ)');
        $passwordConfirmation = (string) $this->secret('Կրկնել գաղտնաբառը');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:14', 'max:255', 'confirmed'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        app(LagerAccessSeeder::class)->run();
        $role = Role::query()->where('name', 'admin')->firstOrFail();
        User::query()->create(['name' => $name, 'email' => $email, 'password' => $password, 'role_id' => $role->id, 'active' => true]);

        $this->components->info("Ստեղծվեց առաջին ադմինիստրատորը՝ {$email}։ Գաղտնաբառը ցուցադրված կամ գրանցված չէ։");

        return self::SUCCESS;
    }
}
