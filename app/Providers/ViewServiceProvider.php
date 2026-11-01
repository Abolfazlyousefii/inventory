<?php

namespace App\Providers;

use App\Models\Category;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class ViewServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        View::composer('*', function ($view) {
            // Standalone management display has no catalog sidebar.
            if (str_starts_with($view->name(), 'tracking.hall-')) {
                return;
            }
            $view->with('categories', Category::orderBy('name')->get());
        });
    }
}
