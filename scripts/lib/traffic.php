<?php

require_once __DIR__.'/traffic/storage.php';

class trafficStatistics {

	public function getData($user, $timePeriod = 5050) {
		return trim( `tail -n{$timePeriod} /var/log/pmss/traffic/{$user} 2>/dev/null` );
	}
    
    public function parseLine($thisLine) {
        $thisLine = trim( $thisLine );        
        if (empty($thisLine)) return false;
        if (strpos($thisLine, ': ') === false) return false;
        $thisLine = explode(': ', $thisLine);
        
        if (count($thisLine) != 2) return false;    // Erroneous data, too many parts :
        $thisTime = strtotime( trim($thisLine[0]) );
        $thisData = trim($thisLine[1]) / 1024 / 1024;   // Transform from bytes to megabytes
        
        if ($thisData > 150000 ) { return false; }    // Pruning erroneous data, 7500Mb in max 6 minutes or so? Yeap.
        
        return array(
            'data' => $thisData,
            'timestamp' => $thisTime
        );
    }
    
    public function saveUserTraffic( $user, $data ) {
        $storage = new \TrafficStorage();
        $storage->ensureRuntime();
        $storage->save($user, $data);
    }

}
