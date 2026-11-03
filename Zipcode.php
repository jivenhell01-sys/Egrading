<?php
/**
 * Philippines Zip Codes for Eastern Visayas Region
 * Contains zip codes for all municipalities/cities in Region VIII - Eastern Visayas
 * Data sourced from kodepos.ph and in-philippines.com
 * 
 * Total coverage: 6 provinces, 139 municipalities/cities
 */

$zipcodes = array(
    'Biliran' => array(
        'Almeria' => '6544',
        'Biliran' => '6549',
        'Cabucgayan' => '6550',
        'Caibiran' => '6548',
        'Culaba' => '6547',
        'Kawayan' => '6545',
        'Maripipi' => '6546',
        'Naval' => '6543'
    ),
    'Eastern Samar' => array(
        'Arteche' => '6822',
        'Balangiga' => '6812',
        'Balangkayan' => '6801',
        'Borongan' => '6800',
        'Can-avid' => '6806',
        'Dolores' => '6817',
        'Gen. MacArthur' => '6805',
        'Giporlos' => '6811',
        'Guiuan' => '6809',
        'Hernani' => '6804',
        'Jipapad' => '6819',
        'Lawaan' => '6813',
        'Llorente' => '6803',
        'Maslog' => '6820',
        'Maydolong' => '6802',
        'Mercedes' => '6808',
        'Oras' => '6818',
        'Quinapondan' => '6810',
        'Salcedo' => '6807',
        'San Julian' => '6814',
        'San Policarpo' => '6821',
        'Sulat' => '6815',
        'Taft' => '6816'
    ),
    'Leyte' => array(
        'Abuyog' => '6510',
        'Alangalang' => '6517',
        'Albuera' => '6542',
        'Babatngon' => '6520',
        'Barugo' => '6519',
        'Bato' => '6525',
        'Baybay City' => '6521',
        'Burauen' => '6516',
        'Calubian' => '6534',
        'Capoocan' => '6530',
        'Carigara' => '6529',
        'Dagami' => '6515',
        'Dulag' => '6505',
        'Hilongos' => '6524',
        'Hindang' => '6523',
        'Inopacan' => '6522',
        'Isabel' => '6539',
        'Jaro' => '6527',
        'Javier' => '6511',
        'Julita' => '6506',
        'Kananga' => '6531',
        'La Paz' => '6508',
        'Leyte' => '6533',
        'MacArthur' => '6509',
        'Mahaplag' => '6512',
        'Matag-ob' => '6532',
        'Matalom' => '6526',
        'Mayorga' => '6507',
        'Merida' => '6540',
        'Ormoc City' => '6541',
        'Palo' => '6501',
        'Palompon' => '6538',
        'Pastrana' => '6514',
        'San Isidro' => '6535',
        'San Miguel' => '6518',
        'Santa Fe' => '6513',
        'Tabango' => '6536',
        'Tabontabon' => '6504',
        'Tacloban City' => '6500',
        'Tanauan' => '6502',
        'Tolosa' => '6503',
        'Tunga' => '6528',
        'Villaba' => '6537'
    ),
    'Northern Samar' => array(
        'Allen' => '6405',
        'Biri' => '6410',
        'Bobon' => '6401',
        'Capul' => '6408',
        'Catarman' => '6400',
        'Catubig' => '6418',
        'Gamay' => '6422',
        'Laoang' => '6411',
        'Lapinig' => '6423',
        'Las Navas' => '6420',
        'Lavezares' => '6404',
        'Lope de Vega' => '6403',
        'Mapanas' => '6412',
        'Mondragon' => '6417',
        'Palapag' => '6421',
        'Pambujan' => '6413',
        'Rosario' => '6416',
        'San Antonio' => '6407',
        'San Isidro' => '6409',
        'San Jose' => '6402',
        'San Roque' => '6415',
        'San Vicente' => '6419',
        'Silvino Lobos' => '6414',
        'Victoria' => '6406'
    ),
    'Samar' => array(
        'Almagro' => '6724',
        'Basey' => '6720',
        'Calbayog City' => '6710',
        'Calbiga' => '6715',
        'Catbalogan City' => '6700',
        'Daram' => '6722',
        'Gandara' => '6706',
        'Hinabangan' => '6713',
        'Jiabong' => '6701',
        'Marabut' => '6721',
        'Matuguinao' => '6708',
        'Motiong' => '6702',
        'Pagsanghan' => '6705',
        'Pinabacdao' => '6716',
        'San Jorge' => '6707',
        'San Jose de Buan' => '6723',
        'San Sebastian' => '6714',
        'Santa Margarita' => '6709',
        'Santa Rita' => '6718',
        'Santo Niño' => '6711',
        'Tagapul-an' => '6712',
        'Talalora' => '6719',
        'Tarangnan' => '6704',
        'Villareal' => '6717',
        'Zumarraga' => '6725'
    ),
    'Southern Leyte' => array(
        'Anahawan' => '6610',
        'Bontoc' => '6604',
        'Hinunangan' => '6608',
        'Hinundayan' => '6609',
        'Libagon' => '6615',
        'Liloan' => '6612',
        'Maasin City' => '6600',
        'Macrohon' => '6601',
        'Malitbog' => '6603',
        'Padre Burgos' => '6601',
        'Pintuyan' => '6614',
        'San Francisco' => '6613',
        'San Juan' => '6611',
        'San Ricardo' => '6617',
        'Silago' => '6607',
        'Sogod' => '6606',
        'Saint Bernard' => '6616',
        'Tomas Oppus' => '6605'
    )
);

/**
 * Helper functions for zip codes data
 */

/**
 * Get all provinces in Eastern Visayas
 * @return array List of all provinces
 */
function get_eastern_visayas_provinces() {
    global $zipcodes;
    return array_keys($zipcodes);
}

/**
 * Get zip codes for a specific province
 * @param string $province Name of province
 * @return array List of municipalities and their zip codes
 */
function get_zipcodes_by_province($province) {
    global $zipcodes;
    return isset($zipcodes[$province]) ? $zipcodes[$province] : array();
}

/**
 * Get zip code for a specific municipality
 * @param string $province Name of province
 * @param string $municipality Name of municipality
 * @return string Zip code or empty string if not found
 */
function get_zipcode_by_municipality($province, $municipality) {
    global $zipcodes;
    return isset($zipcodes[$province][$municipality]) ? $zipcodes[$province][$municipality] : '';
}

/**
 * Get complete zip codes data
 * @return array Complete data structure
 */
function get_zipcodes_data() {
    global $zipcodes;
    return $zipcodes;
}

/**
 * Search for zip code by municipality name across all provinces
 * @param string $municipality Municipality name to search
 * @return array Array containing province and zip code if found
 */
function search_zipcode_by_municipality($municipality) {
    global $zipcodes;
    
    foreach ($zipcodes as $province => $municipalities) {
        if (isset($municipalities[$municipality])) {
            return array(
                'province' => $province,
                'municipality' => $municipality,
                'zipcode' => $municipalities[$municipality]
            );
        }
    }
    
    return array();
}

?>