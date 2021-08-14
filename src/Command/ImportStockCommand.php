<?php

namespace App\Command;

use DateTime;
use App\Entity\StockData;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Console\Command\Command;
use Doctrine\Common\Annotations\Annotation\Enum;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Serializer\Encoder\CsvEncoder;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;


class ImportStockCommand extends Command
{
    protected static $defaultName = 'app:import-stock';
    protected static $defaultDescription = 'Import Stock CSV File.';
    private $appKernel;

    private $csvheader_sku = "SKU";
    private $csvheader_branch = "BRANCH";
    private $csvheader_stock = "STOCK";
    private $isDebug = true;
    private $lowStockQuantity = 1;


    public function __construct(KernelInterface $appKernel, EntityManagerInterface $entityManagerInterface, MailerInterface $mailer)
    {
        $this->appKernel = $appKernel;
        $this->entityManager = $entityManagerInterface;
        $this->mailer = $mailer;


        parent::__construct();
    }


    protected function configure(): void
    {
        $this
            ->addArgument('arg1', InputArgument::OPTIONAL, 'Argument description')
            ->addOption('option1', null, InputOption::VALUE_OPTIONAL, 'Option description');
    }

    private function get_stock_file($fileName)
    {
        return $stock_file = $this->appKernel->getProjectDir() . "/public/files/" . $fileName;
    }

    private function VERIFY_stock_file_exists($fileName, $output)
    {
        $stock_file = $this->get_stock_file($fileName);


        if (file_exists($stock_file)) {
            return $fileName;
        } else {
            $output->writeln('<error>' . $fileName . ' not exists.</error>');
            return FALSE;
        }
    }

    private function VERIFY_stock_file_extension($fileName, $output)
    {

        if ($fileName) {

            $getExtension = explode(".", $fileName);

            if (count($getExtension) > 1) {
                $getCSVExtension = $getExtension[count($getExtension) - 1];

                if ($getCSVExtension == "csv") {

                    return $this->VERIFY_stock_file_exists($fileName, $output);
                } else {

                    $output->writeln('<error>Invalid CSV File.</error>');
                }
            } else {
                $output->writeln('<error>Please type extension name alongwith for e.g. test-stock-file.csv</error>');
            }
        }

        return FALSE;
    }

    private function ASK_user_and_verify_file($input, $output)
    {
        $question = new Question('Please enter the file name for e.g.  test-stock-file.csv. Or press <return> to continue with existing file:  ', 'test-stock-file.csv');


        $fileName = $this->getHelper('question')->ask($input, $output, $question);

        if ($this->VERIFY_stock_file_extension($fileName, $output) === false) {
            return $this->ASK_user_and_verify_file($input, $output);
        }

        return $fileName;
    }

    private function call_AddUpdateStock($stockData, $input, $output, $io)
    {
        $qb = $stockData->createQueryBuilder("sd");

        $getDistinctBranchesResult = $qb->select("sd.branch")
            ->distinct(true)
            ->getQuery()
            ->getResult();

        $branches_array = array();
        foreach ($getDistinctBranchesResult as $branch) {
            $branches_array[] = $branch["branch"];
        }





        $Ask_SKU = new Question("Please type SKU:     ");
        $Get_SKU = $this->getHelper('question')->ask($input, $output, $Ask_SKU);

        //validate if SKU is empty.
        if ($Get_SKU == "") {
            $io->error("SKU cannot be Empty");
        } else {

            $Ask_BRANCH = new ChoiceQuestion("Please type BRANCH:     ", $branches_array);
            $Get_BRANCH = $this->getHelper('question')->ask($input, $output, $Ask_BRANCH);



            $isExists = $stockData->findOneBy([
                "sku" => $Get_SKU,
                "branch" => $Get_BRANCH
            ]);


            if ($isExists) {

                $UserQuestion_ToUpdateData = new ChoiceQuestion("SKU: " . $Get_SKU . " already exists in BRANCH: " . $Get_BRANCH . " with STOCK: " . $isExists->getStock() . ". Do you want to update Quantity?", array("Yes", "No"));
                $UserAnswer_ToUpdateData = $this->getHelper('question')->ask($input, $output, $UserQuestion_ToUpdateData);

                if ($UserAnswer_ToUpdateData == "Yes") {
                    $Ask_Update_StockData = new Question("Please type Stock (Quantity) for SKU: " . $Get_SKU . " - BRANCH: " . $Get_BRANCH . "     ", $isExists->getStock());
                    $Get_Update_StockData = $this->getHelper('question')->ask($input, $output, $Ask_Update_StockData);


                    $tmp_record[$this->csvheader_stock] = $Get_Update_StockData;
                    $this->edit_stock($isExists, $tmp_record);

                    //Flush EM
                    $this->entityManager->flush();

                    $io->success("SKU: " . $Get_SKU . " Updated.");
                } else {
                }
            } else {

                $Ask_New_StockData = new Question("Please type Stock (Quantity) for SKU: " . $Get_SKU . " - BRANCH: " . $Get_BRANCH . "     ", 0);
                $Get_New_StockData = $this->getHelper('question')->ask($input, $output, $Ask_New_StockData);


                $tmp_record[$this->csvheader_sku] = $Get_SKU;
                $tmp_record[$this->csvheader_branch] = $Get_BRANCH;
                $tmp_record[$this->csvheader_stock] = $Get_New_StockData;

                $this->add_stock($tmp_record);

                //Flush EM
                $this->entityManager->flush();

                $io->success("SKU: " . $Get_SKU . " Added.");
            }
        }
    }

    private function call_ListStock($stockData, $input, $output, $io)
    {
        $allRecords = $stockData->findAll();

        $table = new Table($output);
        $table->setHeaders(['SKU', 'BRANCH', 'STOCK', 'LAST UPDATED']);

        $output->write("");
        $output->write("");
        $io->note("Total SKU(s) Count: " . count($allRecords));
        $output->write("");
        $io->info("Please wait... We are processing your entries.");


        $setTableRows  = array();



        

        $tmp_stock_alerts = array();        
        
        foreach ($allRecords as $record) {

            if ($this->isDebug) {
                if (count($setTableRows) > 500) {
                    break;
                }
            }

            if ( $record->getStock() <= $this->lowStockQuantity )
            {
                $tmp_stock_alerts[]     = "<tr><td>". $record->getBranch() ."</td><td>". $record->getSku() ."</td><td>". $record->getStock() ."</td></tr>";
            }

            $setTableRows[]     = array(
                $record->getSku(),
                $record->getBranch(),
                $record->getStock(),
                $record->getUpdatedAt()->format("d-F-Y H:i:a")
            );
        }

        $table->setRows($setTableRows);



        //ALERT WITH EMAIL.
        $this->alert_low_stocks($tmp_stock_alerts);
       

        $table->render();
    }


    private function alert_low_stocks( $tmp_stock_alerts )
    {
        $_body = "<table border='1'><tr><td>Branch</td><td>SKU</td><td>Stock</td></tr>". implode("", $tmp_stock_alerts ) ."</table>";
        $email = (new Email())
            ->from('fairsit.m@gmail.com')
            ->to('fairsit.m@gmail.com')
            ->subject('Stock Alert !!!')
            
            ->html('<p><strong>Stock Alert:</strong></p><br> ' . $_body);


        $this->mailer->send($email);
    }


    private function call_ImportStockCSV($stockData, $input, $output, $io)
    {
        $new_record_entries =  $exists_record_entries = 0;



        //Verify file and proceed.
        $getFileName = $this->ASK_user_and_verify_file($input, $output);
        $output->writeln('<info>Please wait. We are processing your File.</info>');



        //Get Stock Data Entity Repo
        $stock_file = $this->get_stock_file($getFileName);



        //Serialize CSV File.
        $get_records = new Serializer([new ObjectNormalizer()], [new CsvEncoder()]);
        $get_rows = $get_records->decode(file_get_contents($stock_file), 'csv');



        //Init ProgressBar
        $progressBar = new ProgressBar($output, count($get_rows));



        //Start ProgressBar
        $progressBar->start();


        $tmp_stock_alerts = array();

        foreach ($get_rows as $row) {

            if ($this->isDebug) {
                if ($new_record_entries >= 100 || $exists_record_entries >= 100) {
                    break;
                }
            }


            //Find if stock exists 
            $editStock = $stockData->findOneBy([
                "sku" => (int)$row[$this->csvheader_sku],
                "branch" => $row[$this->csvheader_branch]
            ]);


            if ($editStock) {

                $this->edit_stock($editStock, $row);
                $exists_record_entries++;
            } else {

                $this->add_stock($row);
                $new_record_entries++;
            }



            if ( $row[$this->csvheader_stock] <= $this->lowStockQuantity )
            {
                $tmp_stock_alerts[]     = "<tr><td>".$row[$this->csvheader_branch] ."</td><td>". $row[$this->csvheader_sku] ."</td><td>". $row[$this->csvheader_stock] ."</td></tr>";
            }



            $progressBar->advance();
        }


        //ALERT WITH EMAIL.
        $this->alert_low_stocks($tmp_stock_alerts);



        //Finish ProgressBar to 100%
        $progressBar->finish();


        //Flush EM
        $this->entityManager->flush();



        $io = new SymfonyStyle($input, $output);

        $io->success($new_record_entries . " stocks added & " . $exists_record_entries . " records updated Successfully.");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {

       


        $io = new SymfonyStyle($input, $output);

        $questionsArray =  array(
            "Import / Update Stock CSV",
            "List Stock",
            "Add / Update (Single) SKU"
        );

        //First Question User Will See.
        $UserQuestion = new ChoiceQuestion("Please select", $questionsArray, 1);
        $UserAnswer = $this->getHelper('question')->ask($input, $output, $UserQuestion);

        //GET user answer Index (by Question Array)
        $getQuestionIndex = array_search($UserAnswer, $questionsArray);



        //GET Stock Data Entity Repo
        $stockData = $this->entityManager->getRepository(StockData::class);


        if ($getQuestionIndex == 1) {
            $this->call_ListStock($stockData, $input, $output, $io);
        } else if ($getQuestionIndex == 2) {
            $this->call_AddUpdateStock($stockData, $input, $output, $io);
        } else {
            $this->call_ImportStockCSV($stockData, $input, $output, $io);
        }

        return Command::SUCCESS;
    }

    function add_stock($row)
    {
        $newStock = new StockData();
        $newStock->setSku((int)$row[$this->csvheader_sku]);
        $newStock->setBranch($row[$this->csvheader_branch]);
        $newStock->setStock($row[$this->csvheader_stock]);
        $newStock->setCreatedAt(new \DateTimeImmutable('now'));
        $newStock->setUpdatedAt(new \DateTimeImmutable('now'));
        $this->entityManager->persist($newStock);
    }

    function edit_stock($editStock, $row)
    {
        $editStock->setStock($row[$this->csvheader_stock]);
        $this->entityManager->persist($editStock);
        $editStock->setUpdatedAt(new \DateTimeImmutable('now'));
    }
}