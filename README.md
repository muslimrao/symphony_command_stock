# symphony_command_stock


Please follow below STEPS:

1- git clone
2- cd /directory
3- composer install
4- .env (DATABASE_URL) = create your database write your database name
5- php bin/console doctrine:migrations:migrate
6- keep your stock file at public/files (test-stock-file.csv is already there in FILES folder)

Now run below command to run the Stock Query.
7-symfony console app:import-stock
