<?php

namespace App\Services\Imports;

class Vcard
{
    /**
     *
     * Reads a file for parsing, then sends it to $this->readData()
     * and returns the results.
     *
     * @access public
     *
     * @param string $filename The filename to read for vCard information.
     *
     * @return array An array of of vCard information extracted from the
     * file.
     *
     * @see    Contact_Vcard_Parse::readData()
     *
     * @see    Contact_Vcard_Parse::_fromArray()
     *
     */

    function fromFile($filename, $decode_qp = true)
    {
        if (file_exists($filename) && is_readable($filename)) {
            $text = file_get_contents($filename);

            return $this->readData($text, $decode_qp);
        } else {
            return false;
        }
    }

    /**
     *
     * Prepares a block of text for parsing, then sends it through and
     * returns the results from $this->fromArray().
     *
     * @access public
     *
     * @param string $text A block of text to read for vCard information.
     *
     * @return array An array of vCard information extracted from the
     * source text.
     *
     * @see    Contact_Vcard_Parse::_fromArray()
     *
     */
    function readData($text, $decode_qp = true)
    {
        $text = ltrim($text, "\xFE\xFF\xEF\xBB\xBF\0"); // remove BOM
        $n1 = substr_count($text, "\n ");
        $n2 = substr_count($text, "\n  ");

        if ($n1 == $n2) {
            $fold_regex = '(\n)(  )';
            $text = preg_replace("/$fold_regex/i", "", $text);
        } else {
            $fold_regex = '(\n)([ |\t])';
            $text = preg_replace("/$fold_regex/i", "", $text);
        }

        $lines = explode("\n", $text);

        return $this->_fromArray($lines, $decode_qp);
    }

    /**
     *
     * Splits a string into an array at semicolons. (splits at ';' not '\;').
     *
     * @param string $text The string to split into an array.
     * @param bool   $convertSingle
     */

    function splitBySemi($text, $convertSingle = false)
    {
        $regex = '(?<!\\\\)(\;)';
        $tmp = preg_split("/$regex/i", $text);
        if ($convertSingle && count($tmp) == 1) {
            return $tmp[0];
        } else {
            return $tmp;
        }
    }

    /**
     *
     * Splits a string into an array at commas. (splits at ',' not '\,').
     *
     * @param string $text The string to split into an array.
     * @param bool   $convertSingle
     */

    function splitByComma($text, $convertSingle = false)
    {
        $regex = '(?<!\\\\)(\,)';
        $tmp = preg_split("/$regex/i", $text);
        if ($convertSingle && count($tmp) == 1) {
            return $tmp[0];
        } else {
            return $tmp;
        }
    }

    /**
     *
     * Used to make string human-readable after being a vCard value.
     *
     * Converts...
     *     \: => :
     *     \; => ;
     *     \, => ,
     *     literal \n => newline
     *
     * @param mixed $text The text to unescape.
     *
     * @return void
     */

    function unescape(&$text)
    {
        if (is_array($text)) {
            foreach ($text as $key => $val) {
                $this->unescape($val);
                $text[$key] = $val;
            }
        } else {
            $text = $this->vcard_stripcslashes($text, ":;,n\\");
        }
    }

    private function _fromArray($source, $decode_qp = true)
    {
        $info = [];
        $item = [];
        $item_nr = 0;
        $begin = false;
        $card = [];
        foreach ($source as $line) {
            if (trim($line) == '') {
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $left = trim(substr($line, 0, $pos));
            $right = trim(substr($line, $pos + 1, strlen($line)));
            if (!$begin) {
                if (strtoupper($left) == 'BEGIN' &&
                    strtoupper($right) == 'VCARD') {
                    $begin = true;
                }
                continue;
            } else {
                if (strtoupper($left) == 'END' &&
                    strtoupper($right) == 'VCARD') {
                    $info[] = $card;
                    $begin = false;
                    $card = [];
                    $item = [];
                    $item_nr = 0;
                } else {
                    $typedef = $this->_getTypeDef($left);
                    $params = $this->_getParams($left);
                    $item_nr = $this->_getItemNr($left);
                    $this->_decode_qp($params, $right);
                    switch ($typedef) {
                        case 'N':
                            $value = $this->_parseN($right);
                            break;
                        case 'ADR':
                            $value = $this->_parseADR($right);
                            break;
                        case 'NICKNAME':
                            $value = $this->_parseNICKNAME($right);
                            break;
                        case 'ORG':
                            $value = $this->_parseORG($right);
                            break;
                        case 'CATEGORIES':
                        case 'CATEGORY':
                            $value = $this->_parseCATEGORIES($right);
                            break;
                        case 'GEO':
                            $value = $this->_parseGEO($right);
                            break;
                        default:
                            $value = [[$right]];
                            break;
                    }

                    if ($item_nr == 0) {
                        $card[$typedef][] = [
                            'param' => $params,
                            'value' => $value,
                            'item' => 0
                        ];
                    } else {
                        if (empty($item[$item_nr])) {
                            $item[$item_nr] = $typedef;
                            $card[$typedef][] = [
                                'param' => $params,
                                'value' => $value,
                                'item' => $item_nr
                            ];
                        } else {
                            foreach ($card[$item[$item_nr]] as $key => $elem) {
                                if ($elem['item'] == $item_nr) {
                                    $card[$item[$item_nr]][$key][$typedef] = [
                                        'param' => $params,
                                        'value' => $value
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }

        $this->unescape($info);

        return $info;
    }

    private function _getTypeDef($text)
    {
        $split = $this->splitBySemi($text);
        if (substr($split[0], 0, 4) == 'item') {
            $split[0] = substr($split[0], strpos($split[0], '.') + 1);
        }

        return strtoupper($split[0]);
    }

    private function _getItemNr($text)
    {
        $split = $this->splitBySemi($text);
        if (strtoupper(substr($split[0], 0, 4)) == 'ITEM') {
            $split[0] = substr($split[0], 4);

            return (int)$split[0];
        }

        return 0;
    }

    private function _getParams($text)
    {
        $split = $this->splitBySemi($text);
        array_shift($split);
        $params = [];
        foreach ($split as $full) {
            $tmp = explode("=", $full);
            $key = strtoupper(trim($tmp[0]));
            $name = $this->_getParamName($key);
            $listall = trim($tmp[1]);
            $list = $this->splitByComma($listall);
            foreach ($list as $val) {
                if (trim($val) != '') {
                    // 3.0
                    $params[$name][] = trim($val);
                } else {
                    // 2.1
                    $params[$name][] = $key;
                }
            }

            if (count($params[$name]) == 0) {
                unset($params[$name]);
            }
        }

        return $params;
    }

    private function _decode_qp(&$params, &$text)
    {
        foreach ($params as $param_key => $param_val) {
            if (trim(strtoupper($param_key)) == 'ENCODING') {
                foreach ($param_val as $enc_key => $enc_val) {
                    if (trim(strtoupper($enc_val)) == 'QUOTED-PRINTABLE') {
                        $text = utf8_encode(quoted_printable_decode($text));

                        return;
                    }
                }
            }
        }
    }

    private function _getParamName($value)
    {
        static $types = [
            'DOM', 'INTL', 'POSTAL', 'PARCEL', 'HOME', 'WORK', 'PREF', 'VOICE', 'FAX', 'MSG', 'CELL', 'PAGER', 'BBS',
            'MODEM', 'CAR', 'ISDN', 'VIDEO', 'AOL', 'APPLELINK', 'ATTMAIL', 'CIS', 'EWORLD', 'INTERNET', 'IBMMAIL',
            'MCIMAIL', 'POWERSHARE', 'PRODIGY', 'TLX', 'X400', 'GIF', 'CGM', 'WMF', 'BMP', 'MET', 'PMB', 'DIB', 'PICT',
            'TIFF', 'PDF', 'PS', 'JPEG', 'QTIME', 'MPEG', 'MPEG2', 'AVI', 'WAVE', 'AIFF', 'PCM', 'X509', 'PGP'
        ];

        static $values = [
            'INLINE', 'URL', 'CID', 'CONTENT-ID'
        ];

        static $encodings = [
            '7BIT', '8BIT', 'QUOTED-PRINTABLE', 'BASE64'
        ];

        $name = $value;

        if (in_array($value, $types)) {
            $name = 'TYPE';
        } elseif (in_array($value, $values)) {
            $name = 'VALUE';
        } elseif (in_array($value, $encodings, true)) {
            $name = 'ENCODING';
        }

        return $name;
    }

    private function _parseN($text)
    {
        $tmp = array_pad($this->splitBySemi($text), 5, '');

        return [
            $this->splitByComma($tmp[0]), // family (last)
            $this->splitByComma($tmp[1]), // given (first)
            $this->splitByComma($tmp[2]), // addl (middle)
            $this->splitByComma($tmp[3]), // prefix
            $this->splitByComma($tmp[4])  // suffix
        ];
    }

    private function _parseADR($text)
    {
        $tmp = array_pad($this->splitBySemi($text), 7, '');

        return [
            $this->splitByComma($tmp[0]), // pob
            $this->splitByComma($tmp[1]), // extend
            $this->splitByComma($tmp[2]), // street
            $this->splitByComma($tmp[3]), // locality (city)
            $this->splitByComma($tmp[4]), // region (state)
            $this->splitByComma($tmp[5]), // postcode (ZIP)
            $this->splitByComma($tmp[6])  // country
        ];
    }

    private function _parseNICKNAME($text)
    {
        return [$this->splitByComma($text)];
    }

    private function _parseORG($text)
    {
        $tmp = $this->splitbySemi($text);
        $list = [];
        foreach ($tmp as $val) {
            $list[] = [$val];
        }

        if (count($list) < 2)
            $list[] = [""];

        return $list;
    }

    private function _parseCATEGORIES($text)
    {
        return [$this->splitByComma($text)];
    }

    private function _parseGEO($text)
    {
        $tmp = array_pad($this->splitBySemi($text), 2, '');

        return [
            [$tmp[0]], // lat
            [$tmp[1]]  // lon
        ];
    }

    private function vcard_stripcslashes($string, $escapes)
    {
        $escape_mode = 0;
        $out_string = '';

        $len = strlen($string);
        for ($i = 0; $i < $len; $i++) {
            $c = substr($string, $i, 1);
            switch ($escape_mode) {
                case 0:
                    // normal mode
                    if ($c == '\\') {
                        $escape_mode = 1;
                    } else {
                        $out_string .= $c;
                    }
                    break;
                case 1:
                    // escape mode
                    if (strpos($escapes, $c) === false) {
                        //not found - nothing to unescape
                        $out_string .= '\\' . $c;
                    } else {
                        if ($c == "a") $out_string .= "\a";
                        elseif ($c == "b") $out_string .= "\b";
                        elseif ($c == "f") $out_string .= "\f";
                        elseif ($c == "n") $out_string .= "\n";
                        elseif ($c == "r") $out_string .= "\r";
                        elseif ($c == "t") $out_string .= "\t";
                        elseif ($c == "v") $out_string .= "\v";
                        elseif ($c == "0") $out_string .= "\0";
                        else $out_string .= $c;
                    }
                    $escape_mode = 0;
                    break;
                default:
                    // non existent
            }
        }

        return $out_string;
    }

    /**
     *  Get full name from imported file
     *
     * @param array $data
     * @return string
     */
    public function getFullname($data)
    {
        return $this->checkParam($data["FN"][0]["value"][0][0]);
    }

    /**
     *  Get an array of full name parameters
     *
     * @param array $data
     * @return array|false
     */
    public function getParamsName($data)
    {
        if($data["N"][0]['value']){
            $result = [];
            for($i=0; $i < count($data["N"][0]['value']); $i++){
                $result[$i] = $data['N'][0]['value'][$i][0];
            }
            return $result;
        }
        return FALSE;
    }

    /**
     *  Get nickname from imported file
     *
     * @param array $data
     * @return string
     */
    public function getNickname($data)
    {
        return $this->checkParam($data["NICKNAME"][0]["value"][0][0]);
    }

    /**
     *  Receive email and its type
     *
     * @param array $data
     * @return array $result|false
     */
    public function getEmail($data)
    {
        if($data['EMAIL']){
            $result = [];
            for($i=0; $i < count($data['EMAIL']); $i++){
                $result[$i]['value'] = $data['EMAIL'][$i]['value'][0][0];
                $result[$i]['type'] = $data['EMAIL'][$i]['param']['TYPE'][1] ?? 'OTHER';
            }
            return $result;
        }
        return false;
    }

    /**
     *  Checking for the presence of a parameter in the imported file.
     *
     * @param array $param
     * @return boolean
     */
    private function checkParam($param)
    {
        if($param){
            return $param;
        }
        return false;
    }
}
