<?php

namespace App\Providers;

use App\Models\AcademicYear;
use App\Models\Action;
use App\Models\Block;
use App\Models\Cycle;
use App\Models\HostType;
use App\Models\Module;
use App\Models\Secret;
use App\Models\Student;
use App\Models\Task;
use App\Models\User;
use App\Models\Variable;
use App\Policies\ActionPolicy;
use App\Policies\DemoHiddenPolicy;
use App\Policies\DemoReadOnlyPolicy;
use Filament\Tables\Table;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (filled($proxies = config('heimdeye.trusted_proxies'))) {
            TrustProxies::at(array_map('trim', explode(',', $proxies)));
        }

        // Les polítiques només restringeixen en mode demo (App\Support\Demo); fora, ho permeten tot.
        foreach ([AcademicYear::class, Cycle::class, Module::class, Block::class, Task::class, Student::class, Variable::class, HostType::class] as $model) {
            Gate::policy($model, DemoReadOnlyPolicy::class);
        }
        foreach ([User::class, Secret::class] as $model) {
            Gate::policy($model, DemoHiddenPolicy::class);
        }
        Gate::policy(Action::class, ActionPolicy::class);

        // Totes les llistes recorden els filtres, la cerca i l'ordre en tornar-hi (p. ex. des de l'historial d'un check).
        Table::configureUsing(fn (Table $table) => $table
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->persistColumnSearchesInSession()
            ->persistSortInSession());
    }
}
