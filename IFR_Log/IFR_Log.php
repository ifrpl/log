<?

namespace IFR_Log;

class IFR_Log
{
	static $_pid = null;
	static $_file = '';
	static $_file_error = '';
	static $_level = 0;
	static $_start = 0;
	static $_flags;
	static $_gaCode = null;
	static $_filePattern;
	static $_auto = false;
	static $_time;

	const LOG_WEBSERVICE_URL = 'http://websvc.ifresearch.org/log/';

	const DISABLE_SHUTDOWN = 1;
	const DISABLE_START = 2;
	const ENABLE_ECHO = 4;
	const DISABLE_FILE = 8;
	const ENABLE_COLLECT_SERVER = 16;
	const LEVEL_DEFAULT = 0;
    const LEVEL_CRITICAL = 0;
	const LEVEL_ERROR = 1;
    const LEVEL_WARNING = 2;
    const LEVEL_NOTICE = 3;
	const LEVEL_INFO = 4;
	const LEVEL_DEBUG = 5;

	static function makePID()
	{
		self::$_pid = uniqid();
	}

	static function enable($file, $level = IFR_Log::LEVEL_DEFAULT, $flags = 0, $auto = false)
	{
		self::$_flags = $flags;
		self::$_start = self::getTime();
		self::$_level = $level;
		self::$_file = $file;
		self::$_auto = $auto;
		self::$_file_error = preg_replace("/(.+)\.log$/", '$1.error.log', $file);

		if($auto && preg_match('/(.*)?(\d{4}-\d{2}-\d{2})(.*)?/', $file, $match))
		{
			self::$_time = DateTime::createFromFormat('Y-m-d H:i:s',$match[2].' 00:00:00');
			self::$_filePattern = $match[1].'{#DATE#}'.$match[3];
		}

		if(!(self::$_flags & self::DISABLE_START))
		{
			$tmp = debug_backtrace();
			$bin = array_pop($tmp);
			self::log('[Start] '.$bin['file']);
		}
		if(!(self::$_flags & self::DISABLE_SHUTDOWN))
		{
			register_shutdown_function(array("IFR_Log","shutdown"));
		}
	}

	static function changeFile()
	{
		if(self::$_auto and self::$_time)
		{
			$time = self::$_time;
			$now = DateTime::createFromFormat('Y-m-d H:i:s',date('Y-m-d').' 00:00:00');
			if($now > $time)
			{
				self::$_file = $file = str_replace('{#DATE#}', $now->format('Y-m-d'), self::$_filePattern);
				self::$_file_error = preg_replace("/(.+)\.log$/", '$1.error.log', $file);
				self::$_time = $now;
			}
		}
	}

	static function log($message,$level = IFR_Log::LEVEL_INFO)
	{
		if(self::$_pid === null)
		{
			self::makePID();
		}
		self::changeFile();

		if($level<=self::$_level)
		{
			$msg = '['.date('Y-m-d H:i:s').'] ['.self::$_pid.'] [lvl:'.$level.'] '.$message."\n";

			if(self::$_flags & IFR_Log::ENABLE_ECHO)
			{
				echo $msg;
			}
			if(!(self::$_flags & IFR_Log::DISABLE_FILE))
			{
				$old = @umask(0);

//				file_put_contents("php://filter/write=zlib.deflate/resource=".self::$_file.'.gz', $msg, FILE_APPEND);
				@file_put_contents(self::$_file,$msg,FILE_APPEND);

				@chmod(self::$_file,0777);
				@umask($old);
			}
		}

		if($level <= 2)
		{
			$msg = '['.date('Y-m-d H:i:s').'] ['.self::$_pid.'] [lvl:'.$level.'] '.$message."\n";

			if(!(self::$_flags & IFR_Log::DISABLE_FILE))
			{
				$old = @umask(0);

//				file_put_contents("php://filter/write=zlib.deflate/resource=".self::$_file_error.'.gz', $msg, FILE_APPEND);

				@file_put_contents(self::$_file_error,$msg,FILE_APPEND);

				@chmod(self::$_file,0777);
				@umask($old);
			}
		}
	}
	static function getTime()
	{
		list($usec, $sec) = explode(" ", microtime());
		return ((float)$usec + (float)$sec);
	}

	static function shutdown()
	{
		$time = self::getTime()-self::$_start;
		$mem = memory_get_usage();
		self::log('[Stop] [Exec-time:'.$time.'s] [Mem:'.$mem.'b]');

		if(self::$_flags & self::ENABLE_COLLECT_SERVER)
		{
			$data = 'data='.base64_encode(serialize(array(
				'time'=>$time
				,'mem'=>$mem
				,'SERVER_ADDR'=>$_SERVER['SERVER_ADDR']
				,'REMOTE_ADDR'=>$_SERVER['REMOTE_ADDR']
				,'HTTP_HOST'=>$_SERVER['HTTP_HOST']
				,'REQUEST_URI'=>$_SERVER['REQUEST_URI']
				,'QUERY_STRING'=>$_SERVER['QUERY_STRING']
				,'SCRIPT_FILENAME'=>$_SERVER['SCRIPT_FILENAME']
				,'SERVER'=>$_SERVER
				,'POST'=>$_POST
				,'GET'=>$_GET
				,'COOKIE'=>$_COOKIE
			)));

			$ch = curl_init(self::LOG_WEBSERVICE_URL);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
			curl_exec($ch);
			curl_close($ch);
		}
	}

	public static function setGA($code)
	{
		self::$_gaCode = $code;
		if(!defined('GA_CODE'))
		{
			define('GA_CODE', $code);
		}
	}

	public static function getGA()
	{
		if(self::$_gaCode)
		{
			return self::$_gaCode;
		}
		elseif(defined('GA_CODE'))
		{
			return constant('GA_CODE');
		}
		return false;
	}

	public static function logToGA($category, $action = '', $additional = '', $value = null)
	{
		if(!self::getGA())
		{
			return;
		}

		$timestampGA = time();
		if(!empty($additional))
		{
			$additional = "*".urlencode($additional);
		}
		if(!is_null($value))
		{
			$value = '('.urlencode($value).')';
		}
		else
		{
			$value = "";
		}
		$var_utmac = self::$_gaCode;
															//enter the new urchin code
		$var_utmn = rand( 1000000000,9999999999 );							//random request number
		$var_utmdt = '';

		$urchinUrl = 'http://www.google-analytics.com/__utm.gif?';
		$urchinUrl .= 'utmwv=1';						//Tracking code version
		$urchinUrl .= '&utmn='.$var_utmn;				//Unique ID
		$urchinUrl .= '&utmsr=-';						//Screen resolution
		$urchinUrl .= '&utmsc=-';						//Screen color depth
		$urchinUrl .= '&utmul=-';						//Browser language
		$urchinUrl .= '&utmje=0';						//Java enabled
		$urchinUrl .= '&utmfl=-';						//Flash version
		$urchinUrl .= '&utmdt=';						//Page title
		$urchinUrl .= '&utmhn=alerts.logic-immo.be';	//Host Name
		$urchinUrl .= '&utmr=-';						//Referral, complete URL
		$urchinUrl .= '&utmt=event';					//
		$urchinUrl .= '&utme=5('.urlencode($category).'*'.urlencode($action).$additional.')'.$value;//Extensible Parameter
		$urchinUrl .= '&utmac='.$var_utmac;				//Account String
		$urchinUrl .= '&utmcc=__utma%3D999.'.$timestampGA.'.'.$timestampGA.'.'.$timestampGA.'.999.1%3B';
		$urchinUrl .= '&utmp='.urlencode($category.'/'.$action);			//Page request of the current page
		$urchinUrl .= '&utmu=T';

		$cu = curl_init();
		curl_setopt($cu, CURLOPT_HEADER, 1);
		curl_setopt($cu, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($cu, CURLOPT_URL, $urchinUrl);
		$response = curl_exec($cu);
		$return = curl_getinfo($cu);
		curl_close($cu);
//		printr($response);
//		debug($return);
	}

    public static function pdoDebug($query, $placeholders)
    {
        foreach ($placeholders as $key => $value)
        {
            if (!get_magic_quotes_gpc())
                $placeholders[$key] = addslashes($value);
        }
        $query = str_replace("?", "'%s'", $query);
        $query = vsprintf($query, $placeholders);
        return $query;
    }
}
