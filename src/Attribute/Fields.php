<?php

namespace Dakataa\Crud\Attribute;

use Attribute;
use InvalidArgumentException;
use Symfony\Component\ExpressionLanguage\Expression;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Fields
{
	/**
	 * @param array<int|string, string|array<string, mixed>> $fields Field names or field names mapped to column options.
	 */
	public function __construct(
		protected array $fields,
		protected string|array|null $roles = null,
		protected string|Expression|null $permission = null,
		protected bool $useFlatKey = false
	) {
	}

	/**
	 * @return Column[]
	 */
	public function getColumns(): array
	{
		$columns = [];
		foreach ($this->fields as $key => $value) {
			$field = is_int($key) ? $value : $key;
			$options = is_int($key) ? [] : $value;

			if (!is_string($field) || $field === '' || !is_array($options)) {
				throw new InvalidArgumentException('Fields expects field names or field names mapped to options arrays.');
			}

			$columns[] = new Column(
				$field,
				searchable: false,
				visible: false,
				sortable: false,
				options: $options,
				roles: $this->roles,
				permission: $this->permission,
				useFlatKey: $this->useFlatKey
			);
		}

		return $columns;
	}
}
