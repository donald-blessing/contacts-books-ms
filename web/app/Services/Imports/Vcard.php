<?php

namespace App\Services\Imports;

use Exception;

class Vcard
{
    /**
     * @var array
     */
    public array $data = [];

    /**
     * @var string
     */
    public string $file_format = 'vcf';

    /**
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
     */
    public function __construct($file_data = false)
    {
        $this->data = $this->readData($file_data);
    }

    /**
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
     */
    public function readData(string $text, $decode_qp = true): array
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
     *  Parse the array into the desired format.
     *
     * @param $file_data_array
     *
     * @return array
     */
    public function parse(): array
    {
        $data = [];

        try {
            foreach ($this->data as $k => $item) {
                // field: N (array of name parameters)
                $data[$k] = $this->getPartsName($item);

                // field: NICKNAME (pseudonym)
                $data[$k]['nickname'] = $this->getNickname($item);

                // field: PHOTO
                $data[$k]['photo'] = $this->getAvatar($item);

                // field: BDAY (birthday)
                $data[$k]['birthday'] = $this->getBirthday($item);

                // field: NOTE
                $data[$k]['note'] = $this->getNote($item); // доработать

                // field: TEL (phones)
                $data[$k]['phones'] = $this->getPhones($item);

                // field: EMAILs
                $data[$k]['emails'] = $this->getEmails($item);

                // field: ADR (addresses)
                $data[$k]['addresses'] = $this->getAddresses($item);

                // field: ORG (company, department) + TITLE (post)
                $data[$k]['company_info'] = $this->getCompanyInfo($item);

                // field: URL (sites)
                $data[$k]['sites'] = $this->getSites($item);

                // field: X-ABRELATEDNAMES (relation)
                $data[$k]['relations'] = $this->getRelations($item);

                // fields: X-GTALK + X-AIM + X-YAHOO + X-SKYPE + X-QQ + X-MSN + X-ICQ + X-JABBER
                $data[$k]['chats'] = $this->getChats($item);

                // field: CATEGORIES
                $data[$k]['groups'] = $this->getGroups($item);
            }
        } catch (Exception $e) {
            echo $e;
        }

        return $data;
    }

    private function _fromArray($source, $decode_qp = true): array
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

    private function _getTypeDef($text): string
    {
        $split = $this->splitBySemi($text);
        if (substr($split[0], 0, 4) == 'item') {
            $split[0] = substr($split[0], strpos($split[0], '.') + 1);
        }

        return strtoupper($split[0]);
    }

    /**
     * Splits a string into an array at semicolons. (splits at ';' not '\;').
     *
     * @param string $text The string to split into an array.
     * @param bool   $convertSingle
     */
    private function splitBySemi(string $text, bool $convertSingle = false)
    {
        $regex = '(?<!\\\\)(\;)';
        $tmp = preg_split("/$regex/i", $text);
        if ($convertSingle && count($tmp) == 1) {
            return $tmp[0];
        } else {
            return $tmp;
        }
    }

    private function _getParams($text): array
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

    /**
     * Splits a string into an array at commas. (splits at ',' not '\,').
     *
     * @param string $text The string to split into an array.
     * @param bool   $convertSingle
     */
    private function splitByComma($text, $convertSingle = false)
    {
        $regex = '(?<!\\\\)(\,)';
        $tmp = preg_split("/$regex/i", $text);
        if ($convertSingle && count($tmp) == 1) {
            return $tmp[0];
        } else {
            return $tmp;
        }
    }

    private function _getItemNr($text): int
    {
        $split = $this->splitBySemi($text);
        if (strtoupper(substr($split[0], 0, 4)) == 'ITEM') {
            $split[0] = substr($split[0], 4);

            return (int)$split[0];
        }

        return 0;
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

    private function _parseN($text): array
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

    private function _parseADR($text): array
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

    private function _parseNICKNAME($text): array
    {
        return [$this->splitByComma($text)];
    }

    private function _parseORG($text): array
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

    private function _parseCATEGORIES($text): array
    {
        return [$this->splitByComma($text)];
    }

    private function _parseGEO($text): array
    {
        $tmp = array_pad($this->splitBySemi($text), 2, '');

        return [
            [$tmp[0]], // lat
            [$tmp[1]]  // lon
        ];
    }

    /**
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
    private function unescape(&$text)
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

    private function vcard_stripcslashes($string, $escapes): string
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
     *  Checking for the presence of a parameter in the imported file.
     *
     * @param array $param
     *
     * @return boolean
     */
    private function checkParam($param)
    {
        if (isset($param)) {
            return $param;
        }

        return false;
    }

    /**
     *  Get an array of full name parameters
     *
     * @param array $data
     *
     * @return array|false
     */
    public function getPartsName(array $data)
    {
        if (isset($data['N'])) {
            $tmp = $data['N'][0]['value'];

            $result = [];
            $arr_type = [
                'last_name',
                'first_name',
                'middle_name',
                'prefix_name',
                'suffix_name'
            ];

            for ($i = 0; $i < count($tmp); $i++) {
                $value = $this->checkParam($tmp[$i][0]);

                if ($value) {
                    $result[$arr_type[$i]] = $value;
                }
            }

            return $result;
        }

        return false;
    }

    /**
     *  Get nickname from imported file
     *
     * @param array $data
     *
     * @return string
     */
    public function getNickname(array $data): ?string
    {
        if(isset($data["NICKNAME"])){
            return $data["NICKNAME"][0]["value"][0][0];
        }

        return null;
    }

    /**
     *  Receive email and its type
     *
     * @param array $data
     *
     * @return array $result|false
     */
    public function getEmails(array $data): ?array
    {
        if(isset($data['EMAIL'])){
            $tmp = $data['EMAIL'];

            $result = [];
            for ($i = 0; $i < count($tmp); $i++) {
                $result[$i]['value'] = $tmp[$i]['value'][0][0];
                $result[$i]['type'] = $tmp[$i]['param']['TYPE'][1] ?? 'other';
                $result[$i]['type'] = mb_strtolower($result[$i]['type']);
            }

            return $result;
        }

        return null;
    }

    /**
     * get a phone and its type
     *
     * @param array $data
     *
     * @return array $result|false
     */
    public function getPhones(array $data): ?array
    {
        if(isset($data['TEL'])){
            $tmp = $data['TEL'];

            for ($i = 0; $i < count($tmp); $i++) {
                $result[$i]['value'] = str_replace(" ", '', $tmp[$i]['value'][0][0]);

                if (isset($tmp[$i]['param']['TYPE'][0])) {
                    $result[$i]['type'] = $tmp[$i]['param']['TYPE'][0];
                } else {
                    $result[$i]['type'] = $tmp[$i]['X-ABLABEL']['value'][0][0] ?? false;
                }

                $result[$i]['type'] = mb_strtolower($result[$i]['type']);
            }

            return $result;
        }

        return null;
    }

    /**
     *  Get address parameters
     *
     * @param array $data
     *
     * @return array $result|false
     */
    public function getAddresses(array $data)
    {
        if (isset($data['ADR'])) {
            $tmp = $data['ADR'];

            $arr_type = ['po_box', 'address_string2', 'address_string1', 'city', 'provinces', 'postcode', 'country', 'type'];
            for ($i = 0; $i < count($tmp); $i++) {
                $cnt = count($tmp[$i]['value']) + 1;
                for ($j = 0; $j < $cnt; $j++) {
                    $type = $arr_type[$j];
                    if ($type != 'type') {
                        $result[$i][$type] = $tmp[$i]['value'][$j][0];
                    } else {
                        if (isset($tmp[$i]['X-ABLABEL']['value'][0][0])) {
                            $result[$i][$type] = $tmp[$i]['X-ABLABEL']['value'][0][0];
                        }
                    }
                }
                $result[$i] = array_reverse($result[$i]);
            }

            return $result;
        }

        return false;
    }

    /**
     *  Get company info
     *
     * @param array $data
     *
     * @return array $result|false
     */
    public function getCompanyInfo($data)
    {
        if (isset($data['ORG'])) {
            $tmp = $data['ORG'][0]['value'];
            $result['company'] = $tmp[0][0];
            $result['department'] = $tmp[1][0];
            $result['post'] = $data['TITLE'][0]['value'][0][0] ?? false;

            return $result;
        }

        return false;
    }

    /**
     *  Get birthday
     *
     * @param array $data
     *
     * @return array|bool
     */
    public function getBirthday(array $data)
    {
        if (isset($data["BDAY"])) {
            $birthday = $data["BDAY"][0]["value"][0][0];
            $birthday = strtotime(str_replace('/', '-', $birthday));

            return $birthday ? date("Y-m-d", $birthday) : null;
        }

        return false;
    }

    /**
     *  Get info by sites
     *
     * @param array $data
     *
     * @return array $result|false
     */
    public function getSites(array $data)
    {
        if (isset($data['URL'])) {
            $tmp = $data['URL'];
            for ($i = 0; $i < count($tmp); $i++) {
                $result[$i]['value'] = $this->checkParam($tmp[$i]['value'][0][0]);

                if (isset($tmp[$i]['param']['TYPE'][0])) {
                    $result[$i]['type'] = $tmp[$i]['param']['TYPE'][0];
                } else {
                    $result[$i]['type'] = $tmp[$i]['X-ABLABEL']['value'][0][0] ?? false;
                }
                $result[$i]['type'] = mb_strtolower($result[$i]['type']);
            }

            return $result;
        }

        return false;
    }

    /**
     *  Get relationship information
     *
     * @param array $data
     *
     * @return array $result|false
     */
    public function getRelations(array $data)
    {
        if (isset($data['X-ABRELATEDNAMES'])) {
            $tmp = $data['X-ABRELATEDNAMES'];
            $type_info = '';
            for ($i = 0; $i < count($tmp); $i++) {
                switch ($tmp[$i]['X-ABLABEL']['value'][0][0]) {
                    case '_$!<Spouse>!$_':
                        $type_info = 'spouse';
                        break;

                    case '_$!<Child>!$_':
                        $type_info = 'child';
                        break;

                    case '_$!<Mother>!$_':
                        $type_info = 'mother';
                        break;

                    case '_$!<Father>!$_':
                        $type_info = 'father';
                        break;

                    case '_$!<Parent>!$_':
                        $type_info = 'parent';
                        break;

                    case '_$!<Brother>!$_':
                        $type_info = 'brother';
                        break;

                    case '_$!<Sister>!$_':
                        $type_info = 'sister';
                        break;

                    case '_$!<Friend>!$_':
                        $type_info = 'friend';
                        break;

                    case 'RELATIVE':
                        $type_info = 'relative';
                        break;

                    case '_$!<Manager>!$_':
                        $type_info = 'manager';
                        break;

                    case '_$!<Assistant>!$_':
                        $type_info = 'assistant';
                        break;

                    case 'referredBy':
                        $type_info = 'referred_by';
                        break;

                    case '_$!<Partner>!$_':
                        $type_info = 'partner';
                        break;

                    case 'domesticPartner':
                        $type_info = 'domestic_partner';
                        break;

                    default:
                        $type_info = false;
                }

                $result[$i]['value'] = $this->checkParam($tmp[$i]['value'][0][0]);
                $result[$i]['type'] = $type_info;
            }

            return $result;
        }

        return false;
    }

    /**
     *  Get info by chats
     *
     * @param array $data
     *
     * @return array $result|false
     */
    public function getChats(array $data)
    {
        $data_arr_chats = ['X-GTALK', 'X-AIM', 'X-YAHOO', 'X-SKYPE', 'X-QQ', 'X-MSN', 'X-ICQ', 'X-JABBER'];
        $result_arr_chats = ['gtalk', 'aim', 'yahoo', 'skype', 'qq', 'msn', 'isq', 'jabber'];

        for ($i = 0; $i < count($data_arr_chats); $i++) {
            $data_arr = $data_arr_chats[$i];
            if (isset($data[$data_arr])) {
                $result_arr = $result_arr_chats[$i];
                $result[$result_arr] = $data[$data_arr][0]['value'][0][0];
            }
        }

        return $result ?? false;
    }

    /**
     *  We receive a note
     *
     * @param $data
     *
     * @return false|string
     */
    public function getNote($data)
    {
        if (isset($data["NOTE"])) {
            $tmp = $data["NOTE"][0]["value"][0][0];
            $result = strstr($tmp, ' ', true);
        }

        return $result ?? false;
    }

    /**
     *  Get avatar
     *
     * @param array $data
     *
     * @return array $result|bool
     */
    public function getAvatar(array $data)
    {
        if (isset($data['PHOTO'])) {
            return $data['PHOTO'][0]['value'][0][0];
        }

        return null;
    }

    /**
     *  Get groups from imported file
     *
     * @param array $data
     *
     * @return array $result|false
     */
    public function getGroups(array $data)
    {
        if (isset($data['CATEGORIES'])) {
            $tmp = $data['CATEGORIES'][0]['value'][0];

            for ($i = 0; $i < count($tmp); $i++) {
                $result[$i] = $tmp[$i];
            }
        }

        return $result ?? false;
    }
}
