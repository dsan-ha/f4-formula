<?php

namespace App\View;

use App\F4;

class Markdown {

	protected
		//! Parsing rules
		$blocks,
		//! Special characters
		$special;

	/**
	*	Process blockquote
	*	@return string
	*	@param $str string
	**/
	protected function _blockquote($str) {
		$str=preg_replace('/(?<=^|\n)\h?>\h?(.*?(?:\n+|$))/','\1',$str);
		return strlen($str)?
			('<blockquote>'.$this->build($str).'</blockquote>'."\n\n"):'';
	}

	/**
	*	Process whitespace-prefixed code block
	*	@return string
	*	@param $str string
	**/
	protected function _pre($str) {
		$str=preg_replace('/(?<=^|\n)(?: {4}|\t)(.+?(?:\n+|$))/','\1',
			$str);
		return strlen($str)?
			('<pre><code>'.
				$this->esc($this->snip($str)).
			'</code></pre>'."\n\n"):
			'';
	}

	/**
	*	Process fenced code block
	*	@return string
	*	@param $hint string
	*	@param $str string
	**/
	protected function _fence($hint,$str) {
		$str=$this->snip($str);
		$fw=F4::instance();
		if ($fw->get('HIGHLIGHT')) {
			switch (strtolower($hint)) {
				case 'php':
					$str=$fw->highlight($str);
					break;
				case 'apache':
					preg_match_all('/(?<=^|\n)(\h*)'.
						'(?:(<\/?)(\w+)((?:\h+[^>]+)*)(>)|'.
						'(?:(\w+)(\h.+?)))(\h*(?:\n+|$))/',
						$str,$matches,PREG_SET_ORDER);
					$out='';
					foreach ($matches as $match)
						$out.=$match[1].
							($match[3]?
								('<span class="section">'.
									$this->esc($match[2]).$match[3].
								'</span>'.
								($match[4]?
									('<span class="data">'.
										$this->esc($match[4]).
									'</span>'):
									'').
								'<span class="section">'.
									$this->esc($match[5]).
								'</span>'):
								('<span class="directive">'.
									$match[6].
								'</span>'.
								'<span class="data">'.
									$this->esc($match[7]).
								'</span>')).
							$match[8];
					$str='<code>'.$out.'</code>';
					break;
				case 'html':
					preg_match_all(
						'/(?:(?:<(\/?)(\w+)'.
						'((?:\h+(?:\w+\h*=\h*)?".+?"|[^>]+)*|'.
						'\h+.+?)(\h*\/?)>)|(.+?))/s',
						$str,$matches,PREG_SET_ORDER
					);
					$out='';
					foreach ($matches as $match) {
						if ($match[2]) {
							$out.='<span class="xml_tag">&lt;'.
								$match[1].$match[2].'</span>';
							if ($match[3]) {
								preg_match_all(
									'/(?:\h+(?:(?:(\w+)\h*=\h*)?'.
									'(".+?")|(.+)))/',
									$match[3],$parts,PREG_SET_ORDER
								);
								foreach ($parts as $part)
									$out.=' '.
										(empty($part[3])?
											((empty($part[1])?
												'':
												('<span class="xml_attr">'.
													$part[1].'</span>=')).
											'<span class="xml_data">'.
												$part[2].'</span>'):
											('<span class="xml_tag">'.
												$part[3].'</span>'));
							}
							$out.='<span class="xml_tag">'.
								$match[4].'&gt;</span>';
						}
						else
							$out.=$this->esc($match[5]);
					}
					$str='<code>'.$out.'</code>';
					break;
				case 'ini':
					preg_match_all(
						'/(?<=^|\n)(?:'.
						'(;[^\n]*)|(?:<\?php.+?\?>?)|'.
						'(?:\[(.+?)\])|'.
						'(.+?)(\h*=\h*)'.
						'((?:\\\\\h*\r?\n|.+?)*)'.
						')((?:\r?\n)+|$)/',
						$str,$matches,PREG_SET_ORDER
					);
					$out='';
					foreach ($matches as $match) {
						if ($match[1])
							$out.='<span class="comment">'.$match[1].
								'</span>';
						elseif ($match[2])
							$out.='<span class="ini_section">['.$match[2].']'.
								'</span>';
						elseif ($match[3])
							$out.='<span class="ini_key">'.$match[3].
								'</span>'.$match[4].
								($match[5]?
									('<span class="ini_value">'.
										$match[5].'</span>'):'');
						else
							$out.=$match[0];
						if (isset($match[6]))
							$out.=$match[6];
					}
					$str='<code>'.$out.'</code>';
					break;
				default:
					$str='<code>'.$this->esc($str).'</code>';
					break;
			}
		}
		else
			$str='<code>'.$this->esc($str).'</code>';
		return '<pre>'.$str.'</pre>'."\n\n";
	}

	/**
	*	Process horizontal rule
	*	@return string
	**/
	protected function _hr() {
		return '<hr />'."\n\n";
	}

	/**
	*	Process atx-style heading
	*	@return string
	*	@param $type string
	*	@param $str string
	**/
	protected function _atx($type,$str) {
		$level=strlen($type);
		return '<h'.$level.' id="'.$this->slug($str).'">'.
			$this->scan($str).'</h'.$level.'>'."\n\n";
	}

	/**
	*	Process setext-style heading
	*	@return string
	*	@param $str string
	*	@param $type string
	**/
	protected function _setext($str,$type) {
		$level=strpos('=-',$type)+1;
		return '<h'.$level.' id="'.$this->slug($str).'">'.
			$this->scan($str).'</h'.$level.'>'."\n\n";
	}

	function slug($text) {
		return trim(strtolower(preg_replace('/([^\pL\pN])+/u','-',
			trim(strtr($text,$this->diacritics())))),'-');
	}

	function diacritics() {
		return [
			'Ǎ'=>'A','А'=>'A','Ā'=>'A','Ă'=>'A','Ą'=>'A','Å'=>'A',
			'Ǻ'=>'A','Ä'=>'Ae','Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A',
			'Æ'=>'AE','Ǽ'=>'AE','Б'=>'B','Ç'=>'C','Ć'=>'C','Ĉ'=>'C',
			'Č'=>'C','Ċ'=>'C','Ц'=>'C','Ч'=>'Ch','Ð'=>'Dj','Đ'=>'Dj',
			'Ď'=>'Dj','Д'=>'Dj','É'=>'E','Ę'=>'E','Ё'=>'E','Ė'=>'E',
			'Ê'=>'E','Ě'=>'E','Ē'=>'E','È'=>'E','Е'=>'E','Э'=>'E',
			'Ë'=>'E','Ĕ'=>'E','Ф'=>'F','Г'=>'G','Ģ'=>'G','Ġ'=>'G',
			'Ĝ'=>'G','Ğ'=>'G','Х'=>'H','Ĥ'=>'H','Ħ'=>'H','Ï'=>'I',
			'Ĭ'=>'I','İ'=>'I','Į'=>'I','Ī'=>'I','Í'=>'I','Ì'=>'I',
			'И'=>'I','Ǐ'=>'I','Ĩ'=>'I','Î'=>'I','Ĳ'=>'IJ','Ĵ'=>'J',
			'Й'=>'J','Я'=>'Ja','Ю'=>'Ju','К'=>'K','Ķ'=>'K','Ĺ'=>'L',
			'Л'=>'L','Ł'=>'L','Ŀ'=>'L','Ļ'=>'L','Ľ'=>'L','М'=>'M',
			'Н'=>'N','Ń'=>'N','Ñ'=>'N','Ņ'=>'N','Ň'=>'N','Ō'=>'O',
			'О'=>'O','Ǿ'=>'O','Ǒ'=>'O','Ơ'=>'O','Ŏ'=>'O','Ő'=>'O',
			'Ø'=>'O','Ö'=>'Oe','Õ'=>'O','Ó'=>'O','Ò'=>'O','Ô'=>'O',
			'Œ'=>'OE','П'=>'P','Ŗ'=>'R','Р'=>'R','Ř'=>'R','Ŕ'=>'R',
			'Ŝ'=>'S','Ş'=>'S','Š'=>'S','Ș'=>'S','Ś'=>'S','С'=>'S',
			'Ш'=>'Sh','Щ'=>'Shch','Ť'=>'T','Ŧ'=>'T','Ţ'=>'T','Ț'=>'T',
			'Т'=>'T','Ů'=>'U','Ű'=>'U','Ŭ'=>'U','Ũ'=>'U','Ų'=>'U',
			'Ū'=>'U','Ǜ'=>'U','Ǚ'=>'U','Ù'=>'U','Ú'=>'U','Ü'=>'Ue',
			'Ǘ'=>'U','Ǖ'=>'U','У'=>'U','Ư'=>'U','Ǔ'=>'U','Û'=>'U',
			'В'=>'V','Ŵ'=>'W','Ы'=>'Y','Ŷ'=>'Y','Ý'=>'Y','Ÿ'=>'Y',
			'Ź'=>'Z','З'=>'Z','Ż'=>'Z','Ž'=>'Z','Ж'=>'Zh','á'=>'a',
			'ă'=>'a','â'=>'a','à'=>'a','ā'=>'a','ǻ'=>'a','å'=>'a',
			'ä'=>'ae','ą'=>'a','ǎ'=>'a','ã'=>'a','а'=>'a','ª'=>'a',
			'æ'=>'ae','ǽ'=>'ae','б'=>'b','č'=>'c','ç'=>'c','ц'=>'c',
			'ċ'=>'c','ĉ'=>'c','ć'=>'c','ч'=>'ch','ð'=>'dj','ď'=>'dj',
			'д'=>'dj','đ'=>'dj','э'=>'e','é'=>'e','ё'=>'e','ë'=>'e',
			'ê'=>'e','е'=>'e','ĕ'=>'e','è'=>'e','ę'=>'e','ě'=>'e',
			'ė'=>'e','ē'=>'e','ƒ'=>'f','ф'=>'f','ġ'=>'g','ĝ'=>'g',
			'ğ'=>'g','г'=>'g','ģ'=>'g','х'=>'h','ĥ'=>'h','ħ'=>'h',
			'ǐ'=>'i','ĭ'=>'i','и'=>'i','ī'=>'i','ĩ'=>'i','į'=>'i',
			'ı'=>'i','ì'=>'i','î'=>'i','í'=>'i','ï'=>'i','ĳ'=>'ij',
			'ĵ'=>'j','й'=>'j','я'=>'ja','ю'=>'ju','ķ'=>'k','к'=>'k',
			'ľ'=>'l','ł'=>'l','ŀ'=>'l','ĺ'=>'l','ļ'=>'l','л'=>'l',
			'м'=>'m','ņ'=>'n','ñ'=>'n','ń'=>'n','н'=>'n','ň'=>'n',
			'ŉ'=>'n','ó'=>'o','ò'=>'o','ǒ'=>'o','ő'=>'o','о'=>'o',
			'ō'=>'o','º'=>'o','ơ'=>'o','ŏ'=>'o','ô'=>'o','ö'=>'oe',
			'õ'=>'o','ø'=>'o','ǿ'=>'o','œ'=>'oe','п'=>'p','р'=>'r',
			'ř'=>'r','ŕ'=>'r','ŗ'=>'r','ſ'=>'s','ŝ'=>'s','ș'=>'s',
			'š'=>'s','ś'=>'s','с'=>'s','ş'=>'s','ш'=>'sh','щ'=>'shch',
			'ß'=>'ss','ţ'=>'t','т'=>'t','ŧ'=>'t','ť'=>'t','ț'=>'t',
			'у'=>'u','ǘ'=>'u','ŭ'=>'u','û'=>'u','ú'=>'u','ų'=>'u',
			'ù'=>'u','ű'=>'u','ů'=>'u','ư'=>'u','ū'=>'u','ǚ'=>'u',
			'ǜ'=>'u','ǔ'=>'u','ǖ'=>'u','ũ'=>'u','ü'=>'ue','в'=>'v',
			'ŵ'=>'w','ы'=>'y','ÿ'=>'y','ý'=>'y','ŷ'=>'y','ź'=>'z',
			'ž'=>'z','з'=>'z','ż'=>'z','ж'=>'zh','ь'=>'','ъ'=>'',
			'њ'=>'nj','љ'=>'lj','ђ'=>'dj','џ'=>'dz','ћ'=>'c','ј'=>'j',
			'\''=>'',
		];
	}

	/**
	*	Process ordered/unordered list
	*	@return string
	*	@param $str string
	**/
	protected function _li($str) {
		// Initialize list parser
		$len=strlen($str);
		$ptr=0;
		$dst='';
		$first=TRUE;
		$tight=TRUE;
		$type='ul';
		// Main loop
		while ($ptr<$len) {
			if (preg_match('/^\h*[*\-](?:\h?[*\-]){2,}(?:\n+|$)/',
				substr($str,$ptr),$match)) {
				$ptr+=strlen($match[0]);
				// Embedded horizontal rule
				return (strlen($dst)?
					('<'.$type.'>'."\n".$dst.'</'.$type.'>'."\n\n"):'').
					'<hr />'."\n\n".$this->build(substr($str,$ptr));
			}
			elseif (preg_match('/(?<=^|\n)([*+\-]|\d+\.)\h'.
				'(.+?(?:\n+|$))((?:(?: {4}|\t)+.+?(?:\n+|$))*)/s',
				substr($str,$ptr),$match)) {
				$match[3]=preg_replace('/(?<=^|\n)(?: {4}|\t)/','',$match[3]);
				$found=FALSE;
				foreach (array_slice($this->blocks,0,-1) as $regex)
					if (preg_match($regex,$match[3])) {
						$found=TRUE;
						break;
					}
				// List
				if ($first) {
					// First pass
					if (is_numeric($match[1]))
						$type='ol';
					if (preg_match('/\n{2,}$/',$match[2].
						($found?'':$match[3])))
						// Loose structure; Use paragraphs
						$tight=FALSE;
					$first=FALSE;
				}
				// Strip leading whitespaces
				$ptr+=strlen($match[0]);
				$tmp=$this->snip($match[2].$match[3]);
				if ($tight) {
					if ($found)
						$tmp=$match[2].$this->build($this->snip($match[3]));
				}
				else
					$tmp=$this->build($tmp);
				$dst.='<li>'.$this->scan(trim($tmp)).'</li>'."\n";
			}
		}
		return strlen($dst)?
			('<'.$type.'>'."\n".$dst.'</'.$type.'>'."\n\n"):'';
	}


	/**
	*	Ignore raw HTML
	*	@return string
	*	@param $str string
	**/
	protected function _raw($str) {
		return $str;
	}

	/**
	*	Process paragraph
	*	@return string
	*	@param $str string
	**/
	protected function _p($str) {
		$str=trim($str);
		if (strlen($str)) {
			if (preg_match('/^(.+?\n)([>#].+)$/s',$str,$parts))
				return $this->_p($parts[1]).$this->build($parts[2]);
			$str=preg_replace_callback(
				'/([^<>\[]+)?(<[\?%].+?[\?%]>|<.+?>|\[.+?\]\s*\(.+?\))|'.
				'(.+)/s',
				function($expr) {
					$tmp='';
					if (isset($expr[4]))
						$tmp.=$this->esc($expr[4]);
					else {
						if (isset($expr[1]))
							$tmp.=$this->esc($expr[1]);
						$tmp.=$expr[2];
						if (isset($expr[3]))
							$tmp.=$this->esc($expr[3]);
					}
					return $tmp;
				},
				$str
			);
			$str=preg_replace('/\s{2}\r?\n/','<br />',$str);
			return '<p>'.$this->scan($str).'</p>'."\n\n";
		}
		return '';
	}

	/**
	*	Process strong/em/strikethrough spans
	*	@return string
	*	@param $str string
	**/
	protected function _text($str) {
    	$prev = null;
	    while ($prev !== $str) {
	        $prev = $str;

	        // ~~strike~~ (не экранировано слева и справа)
	        $str = preg_replace(
	            '/(?<!\\\\)~~(.*?)(?<!\\\\)~~(?=(?:\s|\p{P}|\z))/u',
	            '<del>$1</del>',
	            $str
	        );

	        // *em*, **strong**, ***strong+em*** и та же логика для _
	        // Требуем один и тот же символ и то же количество при закрытии.
	        $str = preg_replace_callback(
	            '/(?<!\S)(?<!\\\\)(\*{1,3}|_{1,3})(.+?)(?<!\\\\)\1(?=(?:\s|\p{P}|\z))/u',
	            function ($m) {
	                $marks = $m[1];
	                $text  = $m[2];
	                $n = strlen($marks);
	                if ($n === 3) return '<strong><em>' . $text . '</em></strong>';
	                if ($n === 2) return '<strong>' . $text . '</strong>';
	                return '<em>' . $text . '</em>';
	            },
	            $str
	        );
	    }
	    return $str;
	}
	
	/**
	*	Process image span
	*	@return string
	*	@param $str string
	**/
	protected function _img($str) {
		return preg_replace_callback(
			'/!(?:\[(.+?)\])?\h*\(<?(.*?)>?(?:\h*"(.*?)"\h*)?\)/',
			function($expr) {
				return '<img src="'.$expr[2].'"'.
					(empty($expr[1])?
						'':
						(' alt="'.$this->esc($expr[1]).'"')).
					(empty($expr[3])?
						'':
						(' title="'.$this->esc($expr[3]).'"')).' />';
			},
			$str
		);
	}

	/**
	*	Process anchor span
	*	@return string
	*	@param $str string
	**/
	protected function _a($str) {
		return preg_replace_callback(
			'/(?<!\\\\)\[(.+?)(?!\\\\)\]\h*\(<?(.*?)>?(?:\h*"(.*?)"\h*)?\)/',
			function($expr) {
				return '<a href="'.$this->esc($expr[2]).'"'.
					(empty($expr[3])?
						'':
						(' title="'.$this->esc($expr[3]).'"')).
					'>'.$this->scan($expr[1]).'</a>';
			},
			$str
		);
	}

	/**
	*	Auto-convert links
	*	@return string
	*	@param $str string
	**/
	protected function _auto($str) {
		return preg_replace_callback(
			'/`.*?<(.+?)>.*?`|<(.+?)>/',
			function($expr) {
				if (empty($expr[1]) && parse_url($expr[2],PHP_URL_SCHEME)) {
					$expr[2]=$this->esc($expr[2]);
					return '<a href="'.$expr[2].'">'.$expr[2].'</a>';
				}
				return $expr[0];
			},
			$str
		);
	}

	/**
	*	Process code span
	*	@return string
	*	@param $str string
	**/
	protected function _code($str) {
		return preg_replace_callback(
			'/`` (.+?) ``|(?<!\\\\)`(.+?)(?!\\\\)`/',
			function($expr) {
				return '<code>'.
					$this->esc(empty($expr[1])?$expr[2]:$expr[1]).'</code>';
			},
			$str
		);
	}


	/**
	*	Convert characters to HTML entities
	*	@return string
	*	@param $str string
	**/
	function esc($str) {
		if (!$this->special)
			$this->special=[
				'...'=>'&hellip;',
				'(tm)'=>'&trade;',
				'(r)'=>'&reg;',
				'(c)'=>'&copy;'
			];
		foreach ($this->special as $key=>$val)
			$str=preg_replace('/'.preg_quote($key,'/').'/i',$val,$str);
		return htmlspecialchars($str,ENT_COMPAT,
			F4::instance()->get('ENCODING'),FALSE);
	}

	/**
	*	Reduce multiple line feeds
	*	@return string
	*	@param $str string
	**/
	protected function snip($str) {
		return preg_replace('/(?:(?<=\n)\n+)|\n+$/',"\n",$str);
	}

	/**
	*	Scan line for convertible spans
	*	@return string
	*	@param $str string
	**/
	function scan($str) {
		$inline=['img','a','text','auto','code'];
		foreach ($inline as $func)
			$str=$this->{'_'.$func}($str);
		return $str;
	}

	/**
	*	Assemble blocks
	*	@return string
	*	@param $str string
	**/
	protected function build($str) {
		if (!$this->blocks) {
			// Regexes for capturing entire blocks
			$this->blocks=[
				'blockquote'=>'/^(?:\h?>\h?.*?(?:\n+|$))+/',
				'pre'=>'/^(?:(?: {4}|\t).+?(?:\n+|$))+/',
				'fence'=>'/^`{3}\h*(\w+)?.*?[^\n]*\n+(.+?)`{3}[^\n]*'.
					'(?:\n+|$)/s',
				'hr'=>'/^\h*[*_\-](?:\h?[\*_\-]){2,}\h*(?:\n+|$)/',
				'atx'=>'/^\h*(#{1,6})\h?(.+?)\h*(?:#.*)?(?:\n+|$)/u',
				'setext'=>'/^\h*(.+?)\h*\n([=\-])+\h*(?:\n+|$)/u',
				'li'=>'/^(?:(?:[*+\-]|\d+\.)\h.+?(?:\n+|$)'.
					'(?:(?: {4}|\t)+.+?(?:\n+|$))*)+/s',
				'raw'=>'/^((?:<!--.+?-->|'.
					'<(address|article|aside|audio|blockquote|canvas|dd|'.
					'div|dl|fieldset|figcaption|figure|footer|form|h\d|'.
					'header|hgroup|hr|noscript|object|ol|output|p|pre|'.
					'section|table|tfoot|ul|video).*?'.
					'(?:\/>|>(?:(?>[^><]+)|(?R))*<\/\2>))'.
					'\h*(?:\n{2,}|\n*$)|<[\?%].+?[\?%]>\h*(?:\n?$|\n*))/s',
				'p'=>'/^(.+?(?:\n{2,}|\n*$))/s'
			];
		}
		// Treat lines with nothing but whitespaces as empty lines
		$str=preg_replace('/\n\h+(?=\n)/',"\n",$str);
		// Initialize block parser
		$len=strlen($str);
		$ptr=0;
		$dst='';
		// Main loop
		while ($ptr<$len) {
			if (preg_match('/^ {0,3}\[([^\[\]]+)\]:\s*<?(.*?)>?\s*'.
				'(?:"([^\n]*)")?(?:\n+|$)/s',substr($str,$ptr),$match)) {
				// Reference-style link; Backtrack
				$ptr+=strlen($match[0]);
				$tmp='';
				// Catch line breaks in title attribute
				$ref=preg_replace('/\h/','\s',preg_quote($match[1],'/'));
				while ($dst!=$tmp) {
					$dst=preg_replace_callback(
						'/(?<!\\\\)\[('.$ref.')(?!\\\\)\]\s*\[\]|'.
						'(!?)(?:\[([^\[\]]+)\]\s*)?'.
						'(?<!\\\\)\[('.$ref.')(?!\\\\)\]/',
						function($expr) use($match) {
							return (empty($expr[2]))?
								// Anchor
								('<a href="'.$this->esc($match[2]).'"'.
								(empty($match[3])?
									'':
									(' title="'.
										$this->esc($match[3]).'"')).'>'.
								// Link
								$this->scan(
									empty($expr[3])?
										(empty($expr[1])?
											$expr[4]:
											$expr[1]):
										$expr[3]
								).'</a>'):
								// Image
								('<img src="'.$match[2].'"'.
								(empty($expr[2])?
									'':
									(' alt="'.
										$this->esc($expr[3]).'"')).
								(empty($match[3])?
									'':
									(' title="'.
										$this->esc($match[3]).'"')).
								' />');
						},
						$tmp=$dst
					);
				}
			}
			else
				foreach ($this->blocks as $func=>$regex)
					if (preg_match($regex,substr($str,$ptr),$match)) {
						$ptr+=strlen($match[0]);
						$dst.=call_user_func_array(
							[$this,'_'.$func],
							count($match)>1?array_slice($match,1):$match
						);
						break;
					}
		}
		return $dst;
	}

	/**
	*	Render HTML equivalent of markdown
	*	@return string
	*	@param $txt string
	**/
	function convert($txt) {
		$txt=preg_replace_callback(
			'/(<code.*?>.+?<\/code>|'.
			'<[^>\n]+>|\([^\n\)]+\)|"[^"\n]+")|'.
			'\\\\(.)/s',
			function($expr) {
				// Process escaped characters
				return empty($expr[1])?$expr[2]:$expr[1];
			},
			$this->build(preg_replace('/\r\n|\r/',"\n",$txt))
		);
		return $this->snip($txt);
	}
}
