<?php namespace Eld\Bridgevb;

use Illuminate\Support\ServiceProvider;

class BridgevbServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->package('eld/bridgevb');
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app['config']->package('eld/bridgevb', 'eld/bridgevb', 'eld/bridgevb');

        $this->app['bridgevb'] = $this->app->share(
            function ($app) {
                return new BridgeVb;
            }
        );
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return array('bridgevb');
    }
}
