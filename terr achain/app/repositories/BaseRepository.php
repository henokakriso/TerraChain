<?php
declare(strict_types=1);

abstract class BaseRepository
{
    protected string $table;
    protected string $primaryKey = 'id';

    public function find(int $id): ?array
    {
        return App::db()->fetchOne("SELECT * FROM `{$this->table}` WHERE `{$this->primaryKey}` = ?", [$id]);
    }

    public function findBy(string $column, mixed $value): ?array
    {
        return App::db()->fetchOne("SELECT * FROM `{$this->table}` WHERE `$column` = ?", [$value]);
    }

    public function all(string $orderBy = 'id DESC', int $limit = 100, int $offset = 0): array
    {
        return App::db()->fetchAll("SELECT * FROM `{$this->table}` ORDER BY $orderBy LIMIT $limit OFFSET $offset");
    }

    public function create(array $data): int
    {
        return App::db()->insert($this->table, $data);
    }

    public function update(int $id, array $data): void
    {
        App::db()->update($this->table, $data, "`{$this->primaryKey}` = ?", [$id]);
    }

    public function delete(int $id): void
    {
        App::db()->delete($this->table, "`{$this->primaryKey}` = ?", [$id]);
    }

    public function count(string $where = '1=1', array $params = []): int
    {
        $row = App::db()->fetchOne("SELECT COUNT(*) AS c FROM `{$this->table}` WHERE $where", $params);
        return (int)$row['c'];
    }
}
