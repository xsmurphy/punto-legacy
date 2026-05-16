<?php
function generateCSVfromArray($data,$filename='archivo') {
        # Generate CSV data from array
        $fh = fopen('php://temp', 'rw'); # don't create a file, attempt
                                         # to use memory instead

        # write out the headers
        fputcsv($fh, array_keys(current($data)));

        # write out the data
        foreach ( $data as $row ) {
                fputcsv($fh, $row);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"'); 

        echo $csv;
}

$data = [['hola','que','haces'],['nada','y','vos?']];
   
echo str_putcsv($data);

?>