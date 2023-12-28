<?php

namespace Dakataa\Crud\Utils\Doctrine;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator as ORMPaginator;
use Traversable;

class Paginator
{
	protected ORMPaginator $ormPaginator;
	protected int $maxResults = 20;

	public function __construct(protected QueryBuilder $query, protected int $page = 1) {
		$this->ormPaginator = new ORMPaginator($query);

	}

	public function setMaxResults(int $maxResults): static
	{
		$this->maxResults = $maxResults;
		$this->ormPaginator
			->getQuery()
			->setMaxResults($maxResults);
		
		$this->setPage($this->page);

		return $this;
	}

	public function getMaxResults(): int
	{
		return $this->maxResults;
	}

	public function setPage(int $page = 1): self
	{
		$this->page = $page;
		$this->ormPaginator
			->getQuery()
			->setFirstResult($this->getOffset())// Offset
			->setMaxResults($this->getMaxResults()); // Limit

		return $this;
	}

	public function getPage(): int
	{
		return $this->page;
	}
	public function getTotalPages(): int
	{
		return ceil($this->ormPaginator->count() / $this->getMaxResults());
	}

	public function getOffset(int $page = null): int
	{
		return max(0, (($page ?: $this->page ?: 1) * $this->getMaxResults()) - $this->getMaxResults());
	}

	public function count(): int
	{
		return $this->ormPaginator->count();
	}

	public function getLinks(int $maxShownPages = 5): array
	{
		$halfOfShown = round($maxShownPages / 2);
		$start = min($this->page, max($this->getTotalPages() - $maxShownPages, 1));
		$end = min(($this->page + $maxShownPages), $this->getTotalPages());

		return range(max(($start - $halfOfShown), 1), $end);
	}


	public function getResults(): Traversable|array
	{
		return $this->ormPaginator->getIterator();
	}

	public function paginate(): array
	{
		return [
			'items' => $this->getResults(),
			'meta' => [
				'page' => $this->getPage(),
				'totalPages' => $this->getTotalPages(),
				'totalResults' => $this->count(),
				'links' => $this->getLinks(),
				'maxResults' => $this->getMaxResults()
			]
		];
	}
}
