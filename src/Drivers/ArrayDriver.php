<?php

namespace Laravel\Feature\Drivers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Feature\Contracts\FeatureScopeable;
use Laravel\Feature\Events\CheckingKnownFeature;
use Laravel\Feature\Events\CheckingUnknownFeature;

class ArrayDriver
{
    /**
     * The event dispatcher.
     *
     * @var \Illuminate\Contracts\Events\Dispatcher
     */
    protected $events;

    /**
     * The feature state resolvers.
     *
     * @var array<string, (callable(mixed $scope): mixed)>
     */
    protected $featureStateResolvers = [];

    /**
     * Create a new driver instance.
     *
     * @param  \Illuminate\Contracts\Events\Dispatcher  $events
     */
    public function __construct(Dispatcher $events)
    {
        $this->events = $events;
    }

    /**
     * Determine if the feature is active.
     *
     * @param  string  $feature
     * @param  mixed  $scope
     * @return bool
     */
    public function isActive($feature, $scope)
    {
        if ($this->missingResolver($feature)) {
            $this->events->dispatch(new CheckingUnknownFeature($feature, $scope));

            return false;
        }

        $this->events->dispatch(new CheckingKnownFeature($feature, $scope));

        return $this->resolveFeatureState($feature, $scope);
    }

    /**
     * Activate the feature.
     *
     * @param  string  $feature
     * @param  mixed  $scope
     * @return void
     */
    public function activate($feature, $scope)
    {
        $existing = $this->featureStateResolvers[$feature] ?? fn () => false;

        $this->register($feature, fn ($s) => $scope === $s ? true : $existing($s));
    }

    /**
     * Deactivate the feature.
     *
     * @param  string  $feature
     * @param  mixed  $scope
     * @return void
     */
    public function deactivate($feature, $scope)
    {
        $existing = $this->featureStateResolvers[$feature] ?? fn () => false;

        // TODO: this comparison needs to be handled elsewhere and allow for configuration.
        $this->register($feature, fn ($s) => $scope === $s
            ? false
            : $existing($scope));
    }

    /**
     * Register an initial feature state resolver.
     *
     * @param  string  $feature
     * @param  (callable(mixed $scope): mixed)  $resolver
     * @return void
     */
    public function register($feature, $resolver)
    {
        $this->featureStateResolvers[$feature] = $resolver;
    }

    /**
     * Eagerly load the feature state into memory.
     *
     * @param  string|array<string|int, array<int, mixed>|string>  $features
     * @return void
     */
    public function load($features)
    {
        Collection::wrap($features)
            ->mapWithKeys(fn ($value, $key) => is_int($key) ? [$value => []] : [$key => $value])
            ->flatMap(fn ($scope, $feature) => $this->resolve([$feature], $scope))
            ->each(function ($resolved) {
                ['scope' => $scope, 'key' => $key, 'name' => $name] = $resolved;

                if ($this->missingResolver($name)) {
                    return;
                }

                $this->cache[$key] = $this->resolveFeatureState($name, $scope);
            });
    }

    /**
     * Eagerly load the missing feature state into memory.
     *
     * @param  string|array<string|int, array<int, mixed>|string>  $features
     * @return void
     */
    public function loadMissing($features)
    {
        Collection::make($features)
            ->mapWithKeys(fn ($value, $key) => is_int($key) ? [$value => []] : [$key => $value])
            ->flatMap(fn ($scope, $feature) => $this->resolve([$feature], $scope))
            ->each(function ($resolved) {
                ['scope' => $scope, 'key' => $key, 'name' => $name] = $resolved;

                if ($this->missingResolver($name)) {
                    return;
                }

                $this->cache[$key] ??= $this->resolveFeatureState($name, $scope);
            });
    }

    /**
     * Resolve a features state.
     *
     * @param  string  $feature
     * @param  mixed  $scope
     * @return bool
     */
    protected function resolveFeatureState($feature, $scope)
    {
        return $this->featureStateResolvers[$feature]($scope) !== false;
    }

    /**
     * Determine if the feature has no resolver available.
     *
     * @param  string  $feature
     * @return bool
     */
    protected function missingResolver($feature)
    {
        return ! array_key_exists($feature, $this->featureStateResolvers);
    }

    /**
     * Resolve all permutations of the features and scope cache keys.
     *
     * @param  array<int, string>  $features
     * @param  array<int, mixed>  $scope
     * @return \Illuminate\Support\Collection<int, array{ feature: string, scope: mixed, key: string }>
     */
    protected function resolve($features, $scope)
    {
        if ($scope === []) {
            return Collection::make($features)->map(fn ($feature) => [
                'name' => $feature,
                'scope' => null,
                'key' => $feature,
            ]);
        }

        return Collection::make($scope)
            ->crossJoin($features)
            ->mapSpread(fn ($scope, $feature) => [
                'name' => $feature,
                'scope' => $scope,
                'key' => "{$feature}:{$this->resolveKey($scope)}",
            ]);
    }

    /**
     * Resolve the key for the given scope.
     *
     * @param  mixed  $scope
     * @return string
     */
    public function resolveKey($scope)
    {
        if ($scope === null) {
            return $this->nullKey;
        }

        if ($scope instanceof FeatureScopeable) {
            return (string) $scope->toFeatureScopeIdentifier();
        }

        if ($scope instanceof Model) {
            return 'eloquent_model:'.(new $scope)->getMorphClass().':'.$scope->getKey();
        }

        return (string) $scope;
    }
}
