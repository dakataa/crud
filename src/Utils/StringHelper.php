<?php

namespace Dakataa\Crud\Utils;

class StringHelper {
	public static function titlize(string $value): string {
		return preg_replace(['/([A-Z]+)([A-Z][a-z])/', '/([a-z\d])([A-Z])/'], ['\\1 \\2', '\\1 \\2'], $value);
	}
}
