<?php
namespace S24\Tool;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class FixUrlsCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('fix-urls')
            ->setDescription('Fix staging paths in database content')
            ->addArgument(
                'absoluteUrl',
                InputArgument::REQUIRED,
                'Staging URL to replace?'
            )
            ->addArgument(
                'tables',
                InputArgument::IS_ARRAY,
                'Table names to search for absolute paths in (separate multiple names with a space)'
            )
            ->addOption(
                'basePathRemove',
                null,
                InputOption::VALUE_REQUIRED,
                'Base path element of URL to remove. E.g. "/sites/default/files/"'
            )
            ->addOption(
                'basePathReplace',
                null,
                InputOption::VALUE_REQUIRED,
                'Base path element of URL to replace, must be used with basePathRegex. E.g. "/files/"'
            )
            ->addOption(
                'dbEngine',
                null,
                InputOption::VALUE_REQUIRED,
                'Database engine (mysql)'
            )
            ->addOption(
                'host',
                null,
                InputOption::VALUE_REQUIRED,
                'Database host to connect to (localhost)'
            )
            ->addOption(
                'username',
                null,
                InputOption::VALUE_REQUIRED,
                'Database username'
            )
            ->addOption(
                'password',
                null,
                InputOption::VALUE_REQUIRED,
                'Database password'
            )
            ->addOption(
                'database',
                null,
                InputOption::VALUE_REQUIRED,
                'Database name'
            )
            ->addOption(
                'promptmode',
                null,
                InputOption::VALUE_OPTIONAL,
                'Prompt for confirmations? (y)'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Get promptMode - eg whether or not to prompt for confirmation to continue (handy for cron jobs)
        $prompt = $input->getOption('promptmode');
        if (!empty($prompt) && preg_match('/[1n](?:o)?/i', $prompt)) {
            $output->writeln("This script will not prompt you for confirmations");
            $promptMode = false;
        } else {
            $promptMode = true;
        }

        // Get Absolute URL
        $absoluteUrl = $input->getArgument('absoluteUrl');
        if (preg_match('/^http/', $absoluteUrl)) {
            throw new \RuntimeException('Please do not add http/s at the start of the staging URL');
        }
        $absoluteUrl = trim($absoluteUrl);
        $absoluteUrl = trim($absoluteUrl, '/');

        // Get other arguments
        $tables = $input->getArgument('tables');
        $basePathRemove = $input->getOption('basePathRemove');
        $basePathReplace = $input->getOption('basePathReplace');
        if ((!is_null($basePathRemove) || !is_null($basePathReplace)) && (is_null($basePathRemove) || is_null($basePathReplace))) {
            throw new \RuntimeException('You must set both basePathRemove and basePathReplace');
        }

        // Validator
        $helper = $this->getHelper('question');
        $notEmpty = function ($answer) {
            if (empty($answer)) {
                throw new \RuntimeException('You must enter a value');
            }
            return $answer;
        };

        // Get DB options
        $dbEngine      = $input->getOption('dbEngine');
        if ($dbEngine == null) {
            $question = new Question('Enter the database engine (mysql): ', 'mysql');
            $dbEngine = $helper->ask($input, $output, $question);
        }

        $dbHost      = $input->getOption('host');
        if ($dbHost == null) {
            $question = new Question('Enter the database host to connect to (localhost): ', 'localhost');
            $dbHost = $helper->ask($input, $output, $question);
        }

        $dbUsername = $input->getOption('username');
        if ($dbUsername == null) {
            $question = new Question('Enter your database username: ');
            $question->setValidator($notEmpty);
            $question->setMaxAttempts(2);
            $dbUsername = $helper->ask($input, $output, $question);
        }

        $dbPassword = $input->getOption('password');
        if ($dbPassword == null) {
            $question = new Question('Enter your database password (hidden): ');
            $question->setValidator($notEmpty);
            $question->setMaxAttempts(2);
            $question->setHidden(true);         // Don't output the password on the screen
            $question->setHiddenFallback(false);// If we can't hide the password just let it show
            $dbPassword = $helper->ask($input, $output, $question);
        }

        $dbName = $input->getOption('database');
        if ($dbName == null) {
            $question = new Question('Enter your database name: ');
            $question->setValidator($notEmpty);
            $question->setMaxAttempts(2);
            $dbName = $helper->ask($input, $output, $question);
        }

        // Run command
        $output->writeln('Fix staging paths in database content');

        // Connect to DB
        try {
            $db = new \PDO($dbEngine . ':dbname=' . $dbName . ';host=' . $dbHost, $dbUsername, $dbPassword);
        } catch (\PDOException $e) {
            throw new \RuntimeException('Connection failed: ' . $e->getMessage());
        }
        $output->writeln('<info>Connected to database</info>' . PHP_EOL);

        if ($promptMode) {
            if (!$tables) {
                $question = new ConfirmationQuestion("<question>Do you want to continue scanning all database tables for links starting with http(s)://$absoluteUrl?</question> ",
                    false);
            } else {
                $question = new ConfirmationQuestion("<question>Do you want to continue scanning database table/s " . implode(', ',
                        $tables) . " for links starting with http(s)://$absoluteUrl?</question> ", false);
            }
            if (!$helper->ask($input, $output, $question)) {
                return;
            }
        }

        // Fetch all table names from database
        if (!$tables) {
            $output->writeln("Checking all tables in database $dbName ");
            $tables = array();
            foreach ($db->query('SHOW tables') as $row) {
                $tables[] = $row[0];
            }
        }

        // Process table by table
        $replaced = array();
        $skippedTables = array();
        $noPrimaryKey = array();
        foreach ($tables as $table) {
            $table = trim($table);
            $textFields = array();
            $primaryKeys = array();
            $replacementCount = 0;

            $output->writeln(PHP_EOL . "Checking table $table for links starting with http(s)://$absoluteUrl");

            // Build list of text fields
            foreach ($db->query('DESCRIBE `' . $table . '`') as $row) {
                if (in_array(strtolower($row['Type']), array('text', 'tinytext', 'mediumtext', 'longtext', 'char', 'varchar'))) {
                    $textFields[] = $row['Field'];
                }
                if ($row['Key'] == 'PRI') {
                    $primaryKeys[] = $row['Field'];
                }
            }

            if (empty($primaryKeys)) {
                $output->writeln("Cannot replace content in $table since there is no primary key set for this table");
                $noPrimaryKey[] = $table;
            }

            // Load content to check what is incorrect
            $contentToFix = array();
            foreach ($textFields as $field) {
                $sql = 'SELECT ';
                foreach ($primaryKeys as $key) {
                    $sql .= '`' . $key . '`, ';
                }
                $sql .= '`' . $field . '` as content FROM `' . $table . '` WHERE `' . $field . '` LIKE ?';
                $sth = $db->prepare($sql);
                $sth->execute(array('%' . $absoluteUrl . '%'));
                foreach ($sth->fetchAll(\PDO::FETCH_ASSOC) as $row) {

                    // Build primary key refs
                    $pks = array();
                    foreach ($primaryKeys as $key) {
                        $pks[$key] = $row[$key];
                    }

                    // Build regex
                    if (!is_null($basePathRemove)) {
                        $regex = '!((src|href)=("|\'))?http(s)?://' . preg_quote($absoluteUrl, '!') . '/' . preg_quote(ltrim($basePathRemove, '/'), '!') . '!i';
                        $replacement = '$1/' . ltrim($basePathReplace, '/');
                        $summaryRegex = preg_quote($absoluteUrl, '!') . '/' . preg_quote(ltrim($basePathRemove, '/'), '!');

                    } else {
                        $regex = '!((src|href)=("|\'))?http(s)?://' . preg_quote($absoluteUrl, '!') . '/!i';
                        $replacement = '$1/';
                        $summaryRegex = preg_quote($absoluteUrl, '!');
                    }

                    if (!preg_match($regex, $row["content"])) {
                        continue;
                    }

                    // Short text report on what we are replacing
                    $summary = '';
                    if (preg_match_all('!(.{0,45}' . $summaryRegex . '.{0,45})!i', $row['content'], $m, PREG_PATTERN_ORDER)) {
                        foreach ($m[1] as $match) {
                            if (!empty($summary)) {
                                $summary .= PHP_EOL;
                            }
                            $summary .= $match;
                        }
                    }

                    // Replace content
                    $replacedContent = preg_replace($regex, $replacement, $row["content"], -1, $count);
                    $replacedSummary = preg_replace($regex, $replacement, $summary);

                    // Build content to update
                    $contentToFix[] = array(
                        'tbl'   => $table,
                        'fld'   => $field,
                        'pk'    => $pks,
                        'sum'   => $summary,
                        'repsum' => $replacedSummary,
                        'con'   => $replacedContent,
                        'count' => $count
                    );

                    $replacementCount += $count;

                    $output->write('<info>+</info>');
                }
                $output->write('<info>.</info>');
            }
            $output->writeln('');

            // Report on what we're about to do
            if (empty($contentToFix)) {
                $output->writeln("No records found that need fixing");
                continue;
            }

            $output->writeln("<comment>Found " . count($contentToFix) . " records in the database which need fixing</comment>");
            foreach ($contentToFix as $item) {
                $pks = '';
                foreach ($item['pk'] as $key => $val) {
                    if (!empty($pks)) {
                        $pks .= ', ';
                    }
                    $pks .= $key . '=' . $val;
                }
                $output->writeln('');
                $output->writeln('In ' . $item['tbl'] . '.' . $item['fld'] . ' (' . $pks . ')');
                $output->writeln('Found ' . $item['count'] . ' instance/s of the staging URL:');
                $output->writeln('Content: <fg=red>' . $item['sum'] . '</fg=red>');
                $output->writeln('Replacement: <fg=green>' . $item['repsum'] . '</fg=green>');
            }

            $output->writeln("Found " . count($contentToFix) . " records with $replacementCount replacements in the table $table which need fixing");

            $question = new ConfirmationQuestion('<question>Do you want me to replace all content? (y/n)</question> ', false);
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln("Skipping this table");
                $skippedTables[] = $table;
                continue;
            }

            $affectedRows = 0;
            foreach ($contentToFix as $item) {
                $pksSql = '';
                $pksVals = array();
                $values = array($item['con']);
                foreach ($item['pk'] as $key => $val) {
                    if (!empty($pksSql)) {
                        $pksSql .= ' AND ';
                    }
                    $pksSql .= $key . '=?';
                    $values[] = $val;
                }
                $sth = $db->prepare('UPDATE `' . $item['tbl'] . '` SET  `' . $item['fld'] . '` = ? WHERE ' . $pksSql);
                $sth->execute($values);
                $affectedRows += $sth->rowCount();
            }

            $replaced[$table] = $affectedRows;
            $output->writeln("<info>Updated $affectedRows rows</info>");
        }

        // Print summary
        foreach ($replaced as $table => $count) {
            $output->writeln("<info>Updated $count rows in $table</info>");
        }
        if (!empty($skippedTables)) {
            $output->writeln("<info>You skipped updating the following tables: " . implode(', ', $skippedTables) . '</info>');
        }
        foreach ($noPrimaryKey as $table) {
            $output->writeln("<error>Cannot replace content in $table since there is no primary key set for this table</error>");
        }

        $output->writeln(PHP_EOL);
        $output->writeln("<comment>All done!</comment>" . PHP_EOL);
    }

}

