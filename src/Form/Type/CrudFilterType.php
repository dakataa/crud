<?php

namespace Dakataa\Crud\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;

class CrudFilterType extends AbstractType
{
	public const FIELD_EXPOSED_ATTRIBUTE = 'crud_filter_exposed';

	public function finishView(FormView $view, FormInterface $form, array $options): void
	{
		foreach ($form as $name => $field) {
			if ($field->getConfig()->getAttribute(self::FIELD_EXPOSED_ATTRIBUTE, true)) {
				continue;
			}

			unset($view->children[$name]);
			foreach (['data', 'value'] as $key) {
				if (is_array($view->vars[$key] ?? null)) {
					unset($view->vars[$key][$name]);
				}
			}
		}
	}
}
