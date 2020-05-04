<?php

namespace Spatie\Multitenancy;

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Spatie\Multitenancy\Models\Tenant;
use Spatie\Multitenancy\TenantFinder\TenantFinder;

class MultitenancyServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->registerPublishables();
        }

        $this->app->bind(TenantFinder::class, config('multitenancy.tenant_finder'));

        $this
            ->configureRequests()
            ->configureQueue();
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/multitenancy.php', 'multitenancy');
    }

    protected function registerPublishables(): self
    {
        $this->publishes([
            __DIR__ . '/../config/multitenancy.php' => config_path('multitenancy.php'),
        ], 'config');

        if (! class_exists('CreateLandlordTenantsTable')) {
            $this->publishes([
                __DIR__ . '/../database/migrations/landlord/create_landlord_tenants_table.php.stub' => database_path('migrations/landlord' . date('Y_m_d_His', time()) . '_create_tenants_table.php'),
            ], 'migrations');
        }

        return $this;
    }

    public function configureRequests(): self
    {
        if (! $this->app->runningInConsole()) {
            $this->determineCurrentTenant();
        }

        return $this;
    }

    public function configureQueue(): self
    {
        $this->app['queue']->createPayloadUsing(function () {
            return $this->app['tenant'] ? ['tenant_id' => $this->app['tenant']->id] : [];
        });

        $this->app['events']->listen(JobProcessing::class, function ($event) {
            if (isset($event->job->payload()['tenant_id'])) {
                Tenant::find($event->job->payload()['tenant_id'])->configure()->use();
            }
        });

        return $this;
    }

    protected function determineCurrentTenant(): void
    {
        /** @var TenantFinder $tenantFinder */
        $tenantFinder = app(TenantFinder::class);

        $tenant = $tenantFinder->findForRequest(request());

        $tenant->makeCurrent();
    }
}