<?php
/**
 * Biliran Municipalities and Barangays
 * Data sourced from: https://www.philatlas.com/
 * Province of Biliran, Philippines
 */

$biliran_barangays = [
    'Almeria' => [
        'Caucab',
        'Iyosan',
        'Jamorawon',
        'Lo-ok',
        'Matanga',
        'Pili',
        'Poblacion',
        'Pulang Bato',
        'Salangi',
        'Sampao',
        'Tabunan',
        'Talahid',
        'Tamarindo'
    ],
    
    'Biliran' => [
        'Bato',
        'Burabod',
        'Busali',
        'Canila',
        'Hugpa',
        'Julita',
        'Pinangumhan',
        'San Isidro',
        'San Roque',
        'Sanggalang',
        'Villa Enage'
    ],
    
    'Cabucgayan' => [
        'Balaquid',
        'Baso',
        'Bunga',
        'Caanibongan',
        'Casiawan',
        'Esperanza',
        'Langgao',
        'Libertad',
        'Looc',
        'Magbangon',
        'Pawikan',
        'Salawad',
        'Talibong'
    ],
    
    'Caibiran' => [
        'Alegria',
        'Asug',
        'Bari-is',
        'Binohangan',
        'Cabibihan',
        'Caulangohan',
        'Kawayanon',
        'Looc',
        'Manlabang',
        'Maurang',
        'Palanay',
        'Palengke',
        'Tomalistis',
        'Union',
        'Uson',
        'Victory',
        'Villa Vicenta'
    ],
    
    'Culaba' => [
        'Acaban',
        'Bacolod',
        'Binongtoan',
        'Bool Central',
        'Bool East',
        'Bool West',
        'Calipayan',
        'Culaba Central',
        'Guindapunan',
        'Habuhab',
        'Looc',
        'Marvel',
        'Patag',
        'Pinamihagan',
        'Salvacion',
        'San Roque',
        'Virginia'
    ],
    
    'Kawayan' => [
        'Baganito',
        'Balacson',
        'Balite',
        'Bilwang',
        'Bulalacao',
        'Burabod',
        'Buyo',
        'Inasuyan',
        'Kansanok',
        'Mada-o',
        'Mapuyo',
        'Masagaosao',
        'Masagongsong',
        'Poblacion',
        'San Lorenzo',
        'Tabunan North',
        'Tubig Guinoo',
        'Tucdao',
        'Ungale',
        'Villa Cornejo'
    ],
    
    'Maripipi' => [
        'Agutay',
        'Banlas',
        'Bato',
        'Binalayan East',
        'Binalayan West',
        'Binongto-an',
        'Burabod',
        'Calbani',
        'Canduhao',
        'Casibang',
        'Danao',
        'Ermita',
        'Ol-og',
        'Trabugan',
        'Viga'
    ],
    
    'Naval' => [
        'Agpangi',
        'Anislagan',
        'Atipolo',
        'Borac',
        'Cabungaan',
        'Calumpang',
        'Capiñahan',
        'Caraycaray',
        'Catmon',
        'Haguikhikan',
        'Imelda',
        'Larrazabal',
        'Libertad',
        'Libtong',
        'Lico',
        'Lucsoon',
        'Mabini',
        'Padre Inocentes Garcia',
        'Padre Sergio Eamiguel',
        'Sabang',
        'San Pablo',
        'Santissimo Rosario Poblacion',
        'Santo Niño',
        'Talustusan',
        'Villa Caneja',
        'Villa Consuelo'
    ]
];

/**
 * Function to get all municipalities of Biliran
 * @return array List of all municipalities
 */
function getBiliranMunicipalities() {
    global $biliran_barangays;
    return array_keys($biliran_barangays);
}

/**
 * Function to get barangays of a specific municipality
 * @param string $municipality Name of the municipality
 * @return array List of barangays in the municipality
 */
function getBiliranBarangaysByMunicipality($municipality) {
    global $biliran_barangays;
    return isset($biliran_barangays[$municipality]) 
        ? $biliran_barangays[$municipality] 
        : [];
}

/**
 * Function to get all municipalities and their barangays
 * @return array Complete list of municipalities and their barangays
 */
function getAllBiliranBarangays() {
    global $biliran_barangays;
    return $biliran_barangays;
}

/**
 * Function to count total barangays in Biliran
 * @return int Total number of barangays
 */
function countBiliranBarangays() {
    global $biliran_barangays;
    $total = 0;
    foreach ($biliran_barangays as $barangays) {
        $total += count($barangays);
    }
    return $total;
}

/**
 * Function to search for a specific barangay across all municipalities
 * @param string $barangayName Name of the barangay to search
 * @return array List of municipalities where the barangay is found
 */
function searchBiliranBarangay($barangayName) {
    global $biliran_barangays;
    $results = [];
    
    foreach ($biliran_barangays as $municipality => $barangays) {
        if (in_array($barangayName, $barangays)) {
            $results[] = $municipality;
        }
    }
    
    return $results;
}

?>