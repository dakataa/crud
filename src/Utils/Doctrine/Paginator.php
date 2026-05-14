<?php

namespace Dakataa\Crud\Utils\Doctrine;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator as ORMPaginator;


class Paginator
{
	protected ?ORMPaginator $ormPaginator = null;

	public function __construct(
		protected QueryBuilder $query,
		protected int $page = 1,
		protected ?int $maxResults = null
	) {
	}

	protected function getORMPaginator(): ORMPaginator
	{
		$this->query
			->setFirstResult($this->getOffset())
			->setMaxResults($this->maxResults);

		return $this->ormPaginator ?: $this->ormPaginator = new ORMPaginator($this->query);
	}

	public function setMaxResults(?int $maxResults): static
	{
		$this->maxResults = $maxResults;

		return $this;
	}

	public function getMaxResults(): ?int
	{
		return $this->maxResults;
	}

	public function setPage(int $page = 1): self
	{
		$this->page = $page;

		return $this;
	}

	public function getPage(): int
	{
		return $this->page;
	}

	public function getTotalPages(): ?int
	{
		return $this->getMaxResults() ? ceil($this->ormPaginator->count() / $this->getMaxResults()) : null;
	}

	public function getOffset(int $page = null): int
	{
		return max(0, (($page ?: $this->page ?: 1) * $this->getMaxResults()) - $this->getMaxResults());
	}

	public function count(): int
	{
		return $this->getORMPaginator()->count();
	}

	public function getLinks(int $maxVisiblePages = 5): array
	{
		$maxVisiblePages = max(3, $maxVisiblePages);
		$totalPages = $this->getTotalPages();
		if ($totalPages < 2) {
			return [];
		}

		$halfOfShown = ceil($maxVisiblePages / 2);

		$start = max($this->page - 1, $this->page - $halfOfShown);
		$end = min(($this->page + $halfOfShown), $totalPages);

		return range(max($start, 1), $end);
	}


	public function getResults(): iterable
	{
		return $this->getORMPaginator()->getIterator();
	}

	public function paginate(int $maxShownPages = 5): array
	{
		return [
			'items' => $this->getResults(),
			'meta' => [
				'page' => $this->getPage(),
				'totalPages' => $this->getTotalPages(),
				'totalResults' => $this->count(),
				'links' => $this->getLinks($maxShownPages),
				'maxResults' => $this->getMaxResults(),
				'maxShownPages' => $maxShownPages,
			],
		];
	}
}
