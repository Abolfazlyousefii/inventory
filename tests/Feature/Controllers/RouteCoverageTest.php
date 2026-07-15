<?php

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

function application_routes_for_pest(): array
{
    return collect(RouteFacade::getRoutes())
        ->filter(fn (Route $route): bool => $route->getName() !== null)
        ->mapWithKeys(function (Route $route): array {
            $name = $route->getName();
            $methods = implode('|', array_values(array_diff($route->methods(), ['HEAD'])));

            return ["{$methods} {$name} {$route->uri()}" => [$route]];
        })
        ->all();
}

function route_dummy_parameters(Route $route): array
{
    $parameters = [];

    foreach ($route->parameterNames() as $parameter) {
        $bindingField = $route->bindingFieldFor($parameter);
        $where = $route->wheres[$parameter] ?? null;

        $parameters[$parameter] = match (true) {
            $bindingField === 'uuid' => '00000000-0000-4000-8000-000000000001',
            $where === '[0-9]+' || $where === '\\d+' => 1,
            str_contains((string) $where, '0-9') => 1,
            str_ends_with($parameter, 'uuid') || $parameter === 'uuid' => '00000000-0000-4000-8000-000000000001',
            default => 1,
        };
    }

    return $parameters;
}

dataset('named application routes', fn (): array => application_routes_for_pest());

it('registers each named route as an individually auditable endpoint', function (Route $route): void {
    expect($route->getName())->not->toBeNull()
        ->and($route->uri())->not->toBe('')
        ->and($route->methods())->toContain($route->methods()[0]);

    $url = route($route->getName(), route_dummy_parameters($route), false);

    expect($url)->toBeString()->not->toBe('');
})->with('named application routes');

it('maps every controller route to an existing controller function', function (Route $route): void {
    $action = $route->getActionName();

    if ($action === 'Closure') {
        expect($route->getAction('uses'))->toBeInstanceOf(Closure::class);
        return;
    }

    [$class, $method] = str_contains($action, '@') ? explode('@', $action, 2) : [$action, '__invoke'];

    expect(class_exists($class))->toBeTrue("Controller class {$class} must exist for route {$route->getName()}.")
        ->and(method_exists($class, $method))->toBeTrue("Controller action {$class}::{$method} must exist for route {$route->getName()}.");

    $reflection = new ReflectionMethod($class, $method);

    expect($reflection->isPublic())->toBeTrue("Controller action {$class}::{$method} must be public.");
})->with('named application routes');
