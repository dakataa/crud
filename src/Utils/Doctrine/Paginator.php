<?php

namespace Dakataa\Crud\Utils\Doctrine;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator as ORMPaginator;


class Paginator
{
	const DEFAULT_VISIBLE_PAGES = 5;

	const DEFAULT_HARD_MAX_RESULTS = 50;

	protected ?ORMPaginator $ormPaginator = null;

	protected ?int $maxResults = null;

	public function __construct(
		protected QueryBuilder $query,
		protected int $page = 1,
		?int $maxResults = null,
		protected int $hardMaxResults = self::DEFAULT_HARD_MAX_RESULTS
	) {
		$this->setMaxResults($maxResults);
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
		$this->maxResults = $maxResults ? min($maxResults, $this->hardMaxResults) : null;

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
		return $this->getMaxResults() ? ceil(($this->ormPaginator?->count() ?: 0) / $this->getMaxResults()) : null;
	}

	public function getOffset(int $page = null): int
	{
		return max(0, (($page ?: $this->page ?: 1) * $this->getMaxResults()) - $this->getMaxResults());
	}

	public function count(): int
	{
		return $this->getORMPaginator()->count();
	}

	public function getLinks(int $maxVisiblePages = self::DEFAULT_VISIBLE_PAGES): array
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

	public function paginate(int $maxVisiblePages = self::DEFAULT_VISIBLE_PAGES): array
	{
		return [
			'items' => $this->getResults(),
			'meta' => [
				'page' => $this->getPage(),
				'totalPages' => $this->getTotalPages(),
				'totalResults' => $this->count(),
				'links' => $this->getLinks($maxVisiblePages),
				'maxResults' => $this->getMaxResults(),
				'hardMaxResults' => $this->hardMaxResults,
				'maxVisiblePages' => $maxVisiblePages,
			],
		];
	}
}
