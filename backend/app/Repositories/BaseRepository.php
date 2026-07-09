<?php

namespace App\Repositories;

use App\Exceptions\ResourceNotFoundException;
use Illuminate\Database\Eloquent\Model;

abstract class BaseRepository
{
    protected string $modelClass;

    public function findById(int $id): ?Model
    {
        return $this->modelClass::find($id);
    }

    public function findOrFail(int $id): Model
    {
        $model = $this->findById($id);

        if (! $model) {
            throw new ResourceNotFoundException(class_basename($this->modelClass));
        }

        return $model;
    }

    public function create(array $data): Model
    {
        return $this->modelClass::create($data);
    }

    public function update(Model $model, array $data): Model
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(Model $model): void
    {
        $model->delete();
    }

    public function forTenant(int $tenantId)
    {
        return $this->modelClass::forTenant($tenantId);
    }
}
