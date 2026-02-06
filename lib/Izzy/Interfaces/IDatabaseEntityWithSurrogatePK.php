<?php

namespace Izzy\Interfaces;

/**
 * Interface for database entities with a surrogate primary key (auto-increment ID).
 *
 * Extends IDatabaseEntity for entities that use an auto-generated numeric
 * primary key instead of a natural key.
 */
interface IDatabaseEntityWithSurrogatePK extends IDatabaseEntity {
	/**
	 * Get the entity’s primary key value.
	 * @return int|null The ID, or null if not yet persisted.
	 */
	public function getId(): ?int;
}
