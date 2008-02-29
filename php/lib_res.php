<?php

include_once("stopwatch.php");

function get_word(&$data)
{
    if (strlen($data)  < 2)
        die("not enough data");
    $cx = unpack("vc", $data);
    $data = substr($data, 2);
    return $cx["c"];
}

function get_stringorid($data, &$pos)
{
    $len = strlen($data);

    if ((ord($data[$pos]) == 0xff) && (ord($data[$pos + 1]) == 0xff))
    {
        if ($len < 4)
            die("not enough data");
        $pos += 4;
        return (ord($data[$pos - 2]) + (ord($data[$pos - 1]) << 8));
    }

    $ret ="";
    for ($i = $pos; (ord($data[$i]) != 0) || (ord($data[$i+1]) != 0); $i += 2)
    {
        if (ord($data[$i+1]))
            $ret .= '?';
        else
            $ret .= (ord($data[$i])>=0x80 ? '?' : $data[$i]);
    }

    $pos = $i + 2;

    if ($pos >= $len)
        die("not enough data");

    return $ret;
}

function dump_unicode($unistr, $quoted = TRUE)
{
    if ($quoted)
        echo "&quot;";
    for ($i = 0; $i < count($unistr); $i++)
    {
        if (($unistr[$i] >= ord('a') && $unistr[$i] < ord('z'))
                || ($unistr[$i] >= ord('A') && $unistr[$i] < ord('Z'))
                || $unistr[$i] == ord(' '))
            echo chr($unistr[$i]);
        else
            echo "&#x".dechex($unistr[$i]).";";
    }
    if ($quoted)
        echo "&quot;";
}

class ResFile
{
    var $file;

    function ResFile($path)
    {
        $this->file = fopen("$path", "rb");
        if ($this->file == NULL)
            die("Couldn't open resource file");
    }
    
    function enumResources($callback, $lparam = 0)
    {
        fseek($this->file, 0);
        $pos = 0;

        do {
            $data = fread($this->file, 8);

            $len = strlen($data);
            if ($len == 0)
                break;
            if ($len < 8)
                die("Couldn't read header");

            $header = unpack("VresSize/VheaderSize", $data);
            assert($header["headerSize"] > 8);

            $len = $header["headerSize"] - 8;
            $data = fread($this->file, $len);
            if (strlen($data) < $len)
                die("Couldn't read header");

            $strpos = 0;
            $header["type"] = get_stringorid($data, $strpos);
            $header["name"] = get_stringorid($data, $strpos);
            if ($strpos & 3)  /* DWORD padding */
                $strpos += 2;
            $data = substr($data, $strpos);
            $header += unpack("VdataVersion/vmemoryOptions/vlanguage/Vversion/Vcharacteristics", $data);
        
            $pos += ($header["headerSize"] + $header["resSize"] + 3) & 0xfffffffc;

            if (call_user_func($callback, $header, $this->file, $lparam))
                return TRUE;

            fseek($this->file, $pos);
        } while (true);
        return FALSE;
    }

    function load_resource_helper($header, $f, $params)
    {
        $curr_lang = ($params[5] ? ($header["language"] & 0x3ff) : $header["language"]); /* check the ignore_sublang */
        if ($header["type"] == $params[0] && $header["name"] == $params[1] && $curr_lang == $params[2])
        {
            $params[3] = $header;
            $params[4] = fread($f, $header["resSize"]);
            return TRUE;
        }
        return FALSE;
    }

    function loadResource($type, $name, $language, $ignore_sublang = FALSE)
    {
//        $sw = new Stopwatch();
/*      too slow
        if ($this->enumResources(array($this, 'load_resource_helper'), array($type, $name, $language, &$header, &$out, $ignore_sublang)))
        {
            return array($header, $out);
        }*/
        
        fseek($this->file, 0);
        $pos = 0;

        do {
            $data = fread($this->file, 512);

            $len = strlen($data);
            if ($len == 0)
                break;
            if ($len < 8)
                die("Couldn't read header");

            $header = unpack("Va/Vb", $data);
            $resSize = $header["a"];
            $headerSize = $header["b"];
            assert($headerSize > 8 && $headerSize <= $len);

            $strpos = 8;
            $res_type = get_stringorid($data, $strpos);
            if ($res_type == $type)
            {
                $res_name = get_stringorid($data, $strpos);
                if ($res_name == $name)
                {
                    if ($strpos & 3)  /* DWORD padding */
                        $strpos += 2;
                    $data = substr($data, $strpos);
                    $header = unpack("VdataVersion/vmemoryOptions/vlanguage/Vversion/Vcharacteristics", $data);

                    $curr_lang = ($ignore_sublang ? ($header["language"] & 0x3ff) : $header["language"]); /* check the ignore_sublang */
                    if ($curr_lang == $language)
                    {
                        fseek($this->file, $pos + $headerSize);
                        $out = fread($this->file, $resSize);
//                        $sw->stop();
                        return array($header, $out);
                    }
                }
            }
            
            $pos += ($headerSize + $resSize + 3) & 0xfffffffc;
            
            fseek($this->file, $pos);
        } while (true);
        
        return FALSE;
    }
}

class Resource
{
    var $header;

    function Resource($header)
    {
        $this->header = $header;
    }
}

class StringTable extends Resource
{
    var $strings;

    function StringTable($header, $data)
    {
        $this->Resource($header);
        $this->strings = array();
        for ($i = 0; $i < 16; $i++)
        {
            $len = get_word($data);
//            echo "<br/>len=$len";
            $str = array();
            for ($j = 0; $j < $len; $j ++)
                $str[] = get_word($data);
            $this->strings[] = $str;
        }
        if (strlen($data) > 0)
            die("unexpected data in STRINGTABLE resource\n");
    }
    
    function getString($id)
    {
        return $this->strings[$id];
    }
}

function dump_header($header)
{
    var_dump($header);
    echo "<br/>";
    return FALSE;
}

?>
