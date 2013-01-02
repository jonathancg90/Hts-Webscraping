<?php

namespace HTS\Processor;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Link;
use Goutte\Client;
use HTS\Factory\HTSFactory;

class HtsProcessor
{
    private $crawler;
    private $hts;
    private $htsNames;

    public function __construct($uri=null)
    {
        $client = new Client();
        $this->crawler =  $client->request('GET', "{$uri}");
        $this->htsNames = new \ArrayObject();
        //$this->htsNames->append($this->parseNames());
        $this->hts = new \ArrayObject();

    }

    public function catchLinksChapters()
    {
        for ($i =78 ; $i <= 97; $i++) {
            $link = $this->crawler->selectLink("Chapter {$i}")->link();
            $tableXML = \DOMDocument::load($link->getUri());
            $this->processEightDigitRow($tableXML);
            $this->processSpecialEightDigitRow($tableXML);
            $this->processTenDigitRow($tableXML);
            $this->hts->ksort();
        }
        //return $this->parseNames();
        $hts_scraping = json_encode($this->hts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $fp = fopen("hts.json","w+");
        fwrite($fp, $hts_scraping);
        return $hts_scraping;
    }

    private function processEightDigitRow(\DOMDocument $document)
    {
        $elements = $document->getElementsByTagName('eight_digit_row');
        $count = 0;
        while (is_object($element = $elements->item($count))) {
            $insertValue = $this->processHTS($element);
            $this->hts->offsetSet($insertValue["code"], $insertValue);
            $count++;
        }
    }

    private function processSpecialEightDigitRow(\DOMDocument $document)
    {
        $elements = $document->getElementsByTagName('eight_digit_with_00_row');
        $count = 0;
        while(is_object($element = $elements->item($count))) {
            $insertValue = $this->processHTS($element);
            $this->hts->offsetSet($insertValue["code"], $insertValue);
            $count++;
        }
    }

    private function processTenDigitRow(\DOMDocument $document)
    {
        $elements = $document->getElementsByTagName('ten_digit_row');
        $count = 0;
        while(is_object($element = $elements->item($count))) {
            $insertValue = $this->processHTS($element, true, true);
            $this->hts->offsetSet($insertValue["code"], $insertValue);
            $count++;
        }
    }

    /**private function parseNames()
    {
        $data = file_get_contents(__DIR__."/hts_names.json");
        $data = '"'.$data.'"';
        return $data;
    }**/

    private function processHTS(\DOMNode $element, $haveDescription=false, $isTenRow=false)
    {
        $hts = array();
        foreach ($element->childNodes as $child) {
            switch ($child->nodeName) {
                case 'htsno':
                    $hts["code"] = str_replace('.','',$child->nodeValue);
                    $hts["hs"]   = substr($hts["code"], 0, -2);
                    break;
                case 'hts10no':
                    $code = (strlen($child->nodeValue) == 1) ? $child->nodeValue.'0' : $child->nodeValue;
                    $hts["code"] .= $code;
                    break;
                case 'description':
                    $hts["name"] = ($haveDescription == true) ? trim($child->nodeValue) : 'Not available';
                    $hts["name"] = trim($hts["name"]);
                    break;
                case 'unit':
                    $hts["quantity"] = array(trim($child->nodeValue));
                    break;
                case 'units':
                    $units = array();
                    foreach ($child->childNodes as $unit) {
                        if ($unit->nodeName == 'unit') {
                            $units[] = $unit->nodeValue;
                        }
                    }
                    $hts["quantity"] = (empty($units)) ? 'None' : $units;
                    break;
                case 'mfn_tariff':
                    $tariffs = $this->processTariffValue($child->nodeValue);
                    //echo var_dump($tariffs);
                    //echo $tariffs[0]['value'];
                    $hts["tariff_all"]  = (isset($tariffs)) ? $tariffs : 'None';
                    $hts["tariff"]  = 'None';
                    //echo var_dump($hts);//Hts si me devuelve con values
                    //echo "======================SI ENTRO==================\n";
                    //echo var_dump($hts);
                    //echo "\n======================ENTERO==================\n\n\n";
                    break;
                case 'special':
                    $tariffs = $this->processTariff($child->childNodes);
                    $hts["tariff"] = (empty($tariffs)) ? $hts["tariff"] : $tariffs;
                    break;
            }
            //echo $hts["tariff"]['0']['value'];
        }
        //$echo "Tarifa : ".tariffs['value'];
        //echo var_dump($hts);
        $fatherCode = substr($hts["code"], 0, -2);

        if ($isTenRow == true && !$this->selfParent($fatherCode)) {

            $father = ($this->hts->offsetExists($fatherCode)) ?
                          $this->hts->offsetGet($fatherCode) :
                          $this->hts->offsetGet($fatherCode .'00');

            $hts["tariff"] = $father["tariff"];
            $hts["tariff_all"] = $father["tariff_all"];


            if (!array_key_exists('quantity',$father) && $hts["quantity"] != 'None') {
                $this->hts->offsetUnset($father["code"]);
                $father["quantity"] = $hts["quantity"];
                //TODO: process father tariff, insert targetValues based on quantities
                $this->hts->offsetSet($father["code"], $father);
            }

            return $hts;
        }
        //echo var_dump($hts);
        return $hts;
    }

    private function selfParent($code)
    {
        if (!$this->hts->offsetExists($code) && !$this->hts->offsetExists($code.'00')) {
            return true;
        }
    }

    private function createTariffValue($value, $currency, $targetValue)
    {
        return array(
            'value'       => $value,
            'currency'    => $currency,
            'targetValue' => $targetValue
        );
    }

    private function parseTargetValue($targetValue)
    {
        if (strpos($targetValue, $value='No')) {
            return 'No';
        }
        if (strpos($targetValue, $value='head')) {
            return 'No';
        }
        if (strpos($targetValue, $value='each')) {
            return 'No';
        }
        if (strpos($targetValue, $value='1000')) {
            return 'No c/1000 units';
        }
        if (strpos($targetValue, $value='on drained weight')) {
            return 'kg';
        }
        if (strpos($targetValue, $value='on contents and container') ||
            strpos($targetValue, $value='on entire contents of container')) {
            return 'Put description';
        }
        if (strpos($targetValue, $value='less0.020668') ||
            strpos($targetValue, $value='less 0.020668')) {
            return 'kg';
        }
        if (strpos($targetValue, $value='of total sugars')) {
            return 'kg';
        }
        if (strpos($targetValue, $value='on ethyl alcohol content')) {
            return 'pf. liter';
        }
        if (strpos($targetValue, $value='on lead content')) {
            return 'kg';
        }
        if (strpos($targetValue, $value='on tungsten content')) {
            return 'kg';
        }
        if (strpos($targetValue, $value='on molybdenum content')) {
            return 'kg';
        }
        if (strpos($targetValue, $value='on copper content')) {
            return 'kg';
        }
        if (strpos($targetValue, $value='clean')) {
            return 'kg';
        }

        return $targetValue;
    }

    private function parseValue($nodeValue)
    {
        $tariffValue = 'No available';
        //ejm : 1¢/kg, 2.5¢/litro
        if (strpos($nodeValue, $value='¢')) {
            $value .= (strpos($nodeValue,'each')) ? '' : '/';
            $values = explode($value, $nodeValue);
            $target = $this->parseTargetValue($values[1]);
            $tariffValue = $this->createTariffValue($values[0]/100, '$', $target);
        }
        //ejm : 5%, 10%
        if (strpos($nodeValue, $value='%')) {
            if (!strpos($nodeValue, $value='on')) {
                $values = explode($value, $nodeValue);
                //$tariffValue = $this->createTariffValue($values[0], '%', 'default');
                $values[0] = trim($values[0]);
                $values[0] = str_replace('%','',$values[0]);
                $tariffValue = $this->createTariffValue($values[0], '%', 'FOB');
            }
        }
        //==================================================================
        //PROXIMO AÑO
        //==================================================================
        //ejm: 4.4% on the case andstrap, band or bracelet
        if (strpos($nodeValue, $value='%')) {
            $values = explode($value, $nodeValue);
            if (strpos($nodeValue, $value='on')) {
                //echo trim("NODO: ".$nodeValue.": ".$values[1])."\n";
                $values[0] = trim($values[0]);
                $values[0] = str_replace('%','',$values[0]);
                $tariffValue = $this->createTariffValue($values[0], '%', 'No');
                $tariffValue['feature'] = trim($values[1]);
            }
        }
        //==================================================================
        //ejm: $1.189/kg
        if (strpos($nodeValue, $value='$')) {
            $values = explode('/', $nodeValue);
            if(count($values) == 2){
                $tariffValue = $this->createTariffValue($values[0], '$', $values[1]);
            }
        }
        //ejm: $1.61 each
        if (strpos($nodeValue, $value='$')) {
            $nodeValue = trim($nodeValue);
            $values = explode(' ', $nodeValue);
            if(count($values) == 2){
                $tariffValue = $this->createTariffValue($values[0], '$', $values[1]);
            }
        }
        if (strpos($nodeValue, $value='ree')) {
            $tariffValue = $this->createTariffValue(0, '%', 'FOB');
        }

        if (strpos($nodeValue, $value='amc')) {

        }
        if (strpos($nodeValue, $value='t') || strlen($nodeValue) == 1) {

        }

        if (strpos($nodeValue, $value='The rate applicable to the natural juice in heading 200')) {
            $tariffValue = $this->createTariffValue(0, '%', 'FOB');
        }
        if (strpos($nodeValue, $value='pcs')) {

        }
        if (strpos($nodeValue, $value='Gross')) {

        }
        if(strpos($nodeValue, $value='dwb')) {

        }
        if(strpos($nodeValue, $value='g
. . . . . . .F')) {

        }
        if(strpos($nodeValue, $value='adw')) {

        }
        if(strpos($nodeValue, $value='mÂ²')) {

        }
        if(strpos($nodeValue, $value='thousands')) {

        }
        if(strpos($nodeValue, $value='clean')){}

        //echo "Tarifa: ".$tariffValue['value']."\n";    
        return $tariffValue;
    }

    private function processTariffValue($nodeValue, $isArray=true)
    {
        //if find more that one value (ejm: 2¢/kg + 5%)
        //echo "Array: ".$isArray." Nodo: ".$nodeValue." - ";
        //echo $nodeValue."\n";
        if(strpos($nodeValue, $value = '+')) {
            $valuesSum = explode($value, $nodeValue);
            foreach ($valuesSum as $values) {
                //echo $valuesSum;
                $valueSum[] = $this->processTariffValue($values,false);
            }
            return $valueSum;
        }
        $value = $this->parseValue($nodeValue);
        return ($isArray == true) ? array('0' => $value) : $value;
    }

    private function processTariff(\DOMNodeList $tariffs)
    {
        $t = array();
        //echo $tariffs->nodeValue;
        foreach ($tariffs as $tariff) {
            $tariff = explode(" ",$tariff->nodeValue);
            if (count($tariff) > 2) {
                $extraInfo = (strpos($tariff[1],"-")) ? explode("-",$tariff[1]) : $tariff[1];

                if (strpos($tariff[2], $country = 'PE')) {
                    $t["{$country}"] = $extraInfo;
                }

                if (strpos($tariff[1], $country = ',C,') or strpos($tariff[1], $country = ',L,')  
                or strpos($tariff[1], $country = ',K,') ) {
                    $t["CN"] = $extraInfo;
                }
            }
            //TODO: refactor!
            if (strpos($tariff[1], $country = 'PE')) {
                $t["{$country}"] = $this->processTariffValue($tariff[0],false);
            }
            //TODO: refactor!
            if (strpos($tariff[1], $country = ',C,') or strpos($tariff[1], $country = ',L,')  
                or strpos($tariff[1], $country = ',K,') ) {
                $t["CN"] = $this->processTariffValue($tariff[0],false);
            }
        }
        return $t;
    }
}

