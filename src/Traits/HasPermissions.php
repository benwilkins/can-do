<?php


namespace Benwilkins\Authorizer\Traits;


use Benwilkins\Authorizer\AuthorizerFacade as Authorizer;
use Benwilkins\Authorizer\Contracts\Permission;
use Benwilkins\Authorizer\Contracts\Role;
use Benwilkins\Authorizer\Exceptions\PermissionNotGranted;
use Illuminate\Database\Eloquent\Model;
use \Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Trait HasPermissions
 * @package Benwilkins\Authorizer\Traits
 * @property Collection $permissions
 */
trait HasPermissions
{
    /**
     * Boot up the HasRoles trait
     */
    public static function bootHasPermissions()
    {
        /**
         * Tap into the eloquent booted event so we can add the extra observable events.
         */
        app('events')->listen('eloquent.booted: ' .static::class, function(Model $model) {
            $model->addObservableEvents(['permissionGranted', 'permissionRevoked']);
        });
    }

    public static function permissionGranted($callback)
    {
        static::registerModelEvent('permissionGranted', $callback);
    }

    public static function permissionRevoked($callback)
    {
        static::registerModelEvent('permissionRevoked', $callback);
    }

    /**
     * @return MorphToMany
     */
    public function permissions(): MorphToMany
    {
        return $this->morphToMany(
            Authorizer::getClass('permission'),
            'entity',
            config('authorizer.tables.permissions_assigned')
        )->withPivot('team_id');
    }

    /**
     * Gets a merged collection of permissions assigned to both the model itself and
     * any roles that the model is assigned to.
     *
     * @return Collection
     */
    public function allPermissions(): Collection
    {
        if ($this->modelIsRole()) {
            return $this->permissions()->get();

        } else {
            return $this->mergePermissions();
        }
    }

    public function getPermissionsAttribute($value): Collection
    {
        return $this->allPermissions();
    }

    /**
     * Grants an entity one or more permissions.
     *
     * @param string|Permission $permission
     * @param string|int|\Illuminate\Database\Eloquent\Model $team
     * @return self
     */
    public function grantPermission($permission, $team = null): self
    {
        $permission = $this->getSavedPermission($permission);

        $this->permissions()->save($permission, ['team_id' => $this->getTeamForPermission($team)]);
        $this->fireModelEvent('permissionGranted', false);

        return $this;
    }

    public function revokePermission($permission, $team = null): self
    {
        $directPermissions = $this->permissions()->get();
        $teamId = $this->getTeamForPermission($team);
        $permission = $this->getSavedPermission($permission);

        if (!$directPermissions->contains(function ($item, $key) use ($permission, $teamId) {
            return $item->id === $permission->id && $item->pivot->team_id == $teamId;
        })) {
            throw PermissionNotGranted::create($permission->handle, $teamId);
        }

        $this->permissions()->wherePivot('team_id', $teamId)->detach($permission);
        $this->fireModelEvent('permissionRevoked', false);

        return $this;
    }

    /**
     * @param string|Permission $permission
     * @param string|int|\Illuminate\Support\Collection $team
     * @return bool
     */
    public function isGrantedPermission($permission, $team = null): bool
    {
        $teamId = $this->getTeamForPermission($team);

        return $this->allPermissions()->contains(function (Permission $item, $key) use ($permission, $teamId) {
            $matchesTeam = (!$item->pivot->team_id || $item->pivot->team_id == $teamId);

            if (is_string($permission)) { // permission handle
                return ($item->handle === $permission && $matchesTeam);
            }

            return ($item->id === $permission->id && $matchesTeam); // permission model
        });
    }

    /**
     * Merges direct permissions with permissions assigned via roles.
     *
     * @return Collection
     */
    protected function mergePermissions(): Collection
    {
        /** @var Collection $directPermissions */
        $directPermissions = $this->permissions()->get();
        /** @var Collection $permissionsViaRoles */
        $permissionsViaRoles = $this->roles()->with('permissions')->get()
            ->flatMap(function ($role) {
                $permissions = $role->permissions->each(function ($permission) use ($role) {
                    return $permission->pivot->team_id = $role->pivot->team_id;
                });

                return $permissions;
            })->values();

        return $permissionsViaRoles->merge($directPermissions);
    }

    protected function getSavedPermission($permissions)
    {
        $class = Authorizer::getClass('permission');

        if (is_string($permissions)) {
            return $class::findByHandle($permissions);
        }

        if (is_array($permissions)) {
            return $class::whereIn('handle', $permissions)->get();
        }

        return $permissions;
    }

    /**
     * @return bool
     */
    private function modelIsRole(): bool
    {
        return is_a($this, Role::class);
    }

    /**
     * @param $team
     * @return int|null|string
     */
    protected function getTeamForPermission($team)
    {
        if ($this->modelIsRole()) {
            $team = '';

        } elseif ($team) {
            $team = (is_string($team) || is_int($team))
                ? $team
                : $team->id;
        }

        return $team;
    }
}