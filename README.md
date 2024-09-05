# CRUD Dashboard (Backend & Frontend)
Create fast and easy CRUD Dashboard. 
You can use it as REST API or as Standard PHP Application.

## How to Install
1. Setup Symfony Project.
	```shell
    symfony new --webapp crud
    ```
2. Create Entity 
	```shell
    php bin/console make:entity Product
 	...
    php bin/console make:migration
    php bin/console doctrine:migrations:migrate
    ```
3. Add package to composer
   ```shell
   composer require dakataa/crud
   ```
4. Create first controller
