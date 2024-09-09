# CRUD Dashboard (Backend & Frontend)
Create fast and easy CRUD Dashboard. 
You can use it as REST API or as Standard PHP Application.

## How to Install
1. Setup Symfony Project.
	```shell
    symfony new --webapp crud
    ```
2. Create Entity And Form Type
	```shell
	php bin/console make:entity Product
	php bin/console make:form ProductType Product
 	...
    php bin/console make:migration
    php bin/console doctrine:migrations:migrate
    ```
3. Add package to composer
   ```shell
   composer require dakataa/crud
   ```
4. Create first controller.
	Standard way:
	```php
    namespace App\Controller;

	use App\Entity\Product;
	use App\Form\ProductType;
	use Dakataa\Crud\Attribute\EntityType;
	use Dakataa\Crud\Controller\AbstractCrudController;
	use Doctrine\ORM\QueryBuilder;
	use Symfony\Component\HttpFoundation\Request;
	
    #[Route('/product')]
	class ProductController extends AbstractCrudController
	{

		public function getEntityClass(): string
		{
			return Product::class;
		}

		public function getEntityType(): ?EntityType
		{
			return new EntityType(ProductType::class);
		}

		public function buildCustomQuery(Request $request, QueryBuilder $query): AbstractCrudController
		{
			return parent::buildCustomQuery($request, $query);
		}

	}
    ```

	with PHP Attributes

	```php

	```
 
	with Make Command

	```shell
    php symfony crud:make:entity Product ProductType ProductController
	```
