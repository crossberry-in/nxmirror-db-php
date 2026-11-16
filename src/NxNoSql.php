<?php

/**
 * NxMirror DB — official PHP SDK: NoSQL document collections.
 *
 * Document API for databases created with engine "nosql" — same auth, tokens,
 * storage and change feed as SQL, but with JSON documents instead of tables.
 *
 * Filter syntax (AND across keys, dot-paths into nested objects):
 *   ['field' => $value]                 exact match
 *   ['field' => ['$gt' => 10]]          $eq $ne $gt $gte $lt $lte $in $nin
 *                                       $exists $contains $startsWith
 *   ['$or' => [ [..], [..] ]]           top-level OR
 *
 * Part of the crossberry-in/nxmirror-db package (https://nxmirror.netlify.app).
 */

declare(strict_types=1);

namespace NxDb;

final class NxNoSql
{
    private NxDatabase $client;

    public function __construct(NxDatabase $client)
    {
        $this->client = $client;
    }

    /**
     * Every NoSQL operation goes through here: engine guard first (client-side
     * wrong_engine when the engine is already known), then POST nosql/{op}.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function op(string $op, array $payload): array
    {
        $this->client->assertEngine('nosql');
        $body = array_merge(['databaseId' => $this->client->databaseId], $payload);
        return $this->client->sendRequest('nosql/' . $op, 'POST', $body);
    }

    /**
     * Insert one document or an array of documents.
     *
     * @param array<string,mixed>|array<int,array<string,mixed>> $docOrDocs
     * @return array<string,mixed> { inserted, ids, lastInsertId }
     */
    public function insert(string $collection, array $docOrDocs): array
    {
        $payload = array_is_list($docOrDocs)
            ? ['docs' => $docOrDocs]
            : ['doc' => $docOrDocs];
        return $this->op('insert', array_merge(['collection' => $collection], $payload));
    }

    /**
     * Query documents.
     *
     * @param array<string,mixed> $options { filter?, projection?, sort?, limit?, offset? }
     * @return array<string,mixed> { docs, total, truncated }
     */
    public function find(string $collection, array $options = []): array
    {
        return $this->op('find', array_merge(['collection' => $collection], $options));
    }

    /**
     * Fetch one document by _id — null when missing (HTTP 404).
     *
     * @return array<string,mixed>|null
     */
    public function get(string $collection, string $id): ?array
    {
        try {
            $result = $this->op('get', ['collection' => $collection, 'id' => $id]);
        } catch (NxDbError $error) {
            if ($error->status === 404) {
                return null;
            }
            throw $error;
        }
        return isset($result['doc']) && is_array($result['doc']) ? $result['doc'] : null;
    }

    /** Count documents matching a filter. */
    public function count(string $collection, ?array $filter = null): int
    {
        $payload = ['collection' => $collection];
        if ($filter !== null) {
            $payload['filter'] = $filter;
        }
        $result = $this->op('count', $payload);
        return (int) ($result['count'] ?? 0);
    }

    /**
     * Update by _id or filter. Multi-match updates REQUIRE $many = true —
     * otherwise the server answers 409 ambiguous_match.
     *
     * @param string|array<string,mixed> $target document id or filter
     * @param array<string,mixed> $patch fields/dot-paths to set
     * @return array<string,mixed> { updated, ids }
     */
    public function update(string $collection, string|array $target, array $patch, bool $many = false): array
    {
        $payload = is_string($target) ? ['id' => $target] : ['filter' => $target];
        return $this->op('update', array_merge(
            ['collection' => $collection],
            $payload,
            ['patch' => $patch, 'many' => $many]
        ));
    }

    /**
     * Replace a document by _id (keeps _id/_createdAt).
     *
     * @param array<string,mixed> $doc
     * @return array<string,mixed> { replaced }
     */
    public function replace(string $collection, string $id, array $doc): array
    {
        return $this->op('replace', ['collection' => $collection, 'id' => $id, 'doc' => $doc]);
    }

    /**
     * Delete by _id or filter. Multi-match deletes REQUIRE $many = true.
     *
     * @param string|array<string,mixed> $target document id or filter
     * @return array<string,mixed> { deleted, ids }
     */
    public function delete(string $collection, string|array $target, bool $many = false): array
    {
        $payload = is_string($target) ? ['id' => $target] : ['filter' => $target];
        return $this->op('delete', array_merge(
            ['collection' => $collection],
            $payload,
            ['many' => $many]
        ));
    }

    /**
     * List collections.
     *
     * @return array<int,array<string,mixed>> [{ name, count, bytes }, ...]
     */
    public function list(): array
    {
        $result = $this->op('collections', []);
        return isset($result['collections']) && is_array($result['collections'])
            ? $result['collections']
            : [];
    }

    /**
     * Drop a whole collection (needs schema permission).
     *
     * @return array<string,mixed> { dropped, documents }
     */
    public function drop(string $collection): array
    {
        return $this->op('drop', ['collection' => $collection]);
    }
}
