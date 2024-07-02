<?php

require '/var/www/html/config.inc.php';
$conn12 = mysqli_connect($host, $user, $pass, 'retool');
if (!$conn12) {
    die("Connection to 'retool' database failed: " . mysqli_connect_error());
}

// Query datasets to process
$stage2 = "SELECT * FROM `datasets`";
$query2 = mysqli_query($conn12, $stage2);
if (!$query2) {
    die("Query to fetch datasets failed: " . mysqli_error($conn12));
}

// Iterate through each dataset
while ($row2 = mysqli_fetch_assoc($query2)) {
    $dxname = $row2['dbname'];
    $raddress = $row2['raddress'];

    // Skip datasets without a remote wallet address
    if (empty($raddress)) {
        echo "Skipped dataset $dxname, as it has no remote wallet address.\n";
        continue;
    }

    echo "Processing dataset $dxname\n";

    // Connect to dataset-specific database
    $conna = mysqli_connect($host, $user, $pass, $dxname);
    if (!$conna) {
        die("Connection to database '$dxname' failed: " . mysqli_connect_error());
    }

    // Query to get unique miners from 'deals' table
    $query = "SELECT DISTINCT miner FROM deals";
    $result = mysqli_query($conna, $query);
    if (!$result) {
        die("Query to fetch miners from 'deals' table failed: " . mysqli_error($conna));
    }

    // Iterate through each miner
    while ($row = mysqli_fetch_assoc($result)) {
        $miner = $row['miner'];

        // Construct the command to execute with sudo
        $command = [
            'sudo',
            '-E',  // Preserve environment variables
            'FULLNODE_API_INFO=<redacted>:/ip4/127.0.0.1/tcp/1234/http',
            '/usr/local/bin/boost',  // Adjust path to boost executable
            'list-claims',
            $miner
        ];

        // Execute the command and capture output
        $output = [];
        exec(implode(' ', $command), $output);

        // Process $output to filter and update deals table
        foreach ($output as $line) {
            // Extract fields from the line
            $fields = preg_split('/\s+/', trim($line));
            if (count($fields) >= 8) {
                $dealnumber = mysqli_real_escape_string($conna, $fields[0]);
                $dataCID = mysqli_real_escape_string($conna, $fields[3]);
                $active = mysqli_real_escape_string($conna, $fields[7]);
                $provider = 'f0' . mysqli_real_escape_string($conna, $fields[1]); // Prepend 'f0' to provider ID

                // Update deals table if cid matches and dealnumber is not empty
                $updateQuery = "UPDATE deals SET dealnumber = '$dealnumber', active = '$active' WHERE (cid = '$dataCID' OR did = '$dataCID') AND miner = '$provider' AND (dealnumber IS NULL OR dealnumber != '')";
                $updateResult = mysqli_query($conna, $updateQuery);
                if (!$updateResult) {
                    echo "Failed to update deals table: " . mysqli_error($conna) . "\n";
                } else {
                    echo "Updated deals table for cid: $dataCID and miner: $provider for dealnumber $dealnumber \n";
                }
            }
        }
    }

    // Close connection to dataset-specific database
    mysqli_close($conna);
}

// Close connection to 'retool' database
mysqli_close($conn12);
?>
