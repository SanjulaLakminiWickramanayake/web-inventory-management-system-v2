<?php
$pw = array('123456','manager123','Jay123','Jay456','Min123','Min456','Mad123','Mad456','Kad123','Kad456','Kan123','Kan456','Pol123','Pol456','New123','New456','Hab123','Hab456','Kek123','Kek456','Ara123','Ara456','Dam123','Dam456');
foreach ($pw as $p) {
    echo password_hash($p, PASSWORD_DEFAULT) . PHP_EOL;
}
