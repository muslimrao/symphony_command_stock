# symphony_command_stock


Please follow below STEPS:

1- git clone https://github.com/muslimrao/symphony_command_stock.git

2- cd .\symphony_command_stock\

3- composer install

4- .env (DATABASE_URL) = create your database write your database name

5- php bin/console doctrine:migrations:migrate

6- keep your stock file at public/files (test-stock-file.csv is already there in FILES folder)


<hr>

<b>Now run below command to run the Stock Query.</b>

7-symfony console app:import-stock
