<?
namespace App;

use App\Utils\Assets;   
use App\F4;
use App\Component\ComponentManager;
/**
 * 
 */
final class App
{
	private F4 $f4; 
	private Assets $assets;
	private $meta; 
	public array $alerts = [];
	public ComponentManager $component_manager; 
	private $layout = 'layout.php';
	const BUFFER = ['head' => '{{__headBufferf3}}', 'footer' => '{{__footerBufferf3}}'];
	
	function __construct(F4 $f4, Assets $assets, ComponentManager $component_manager)
	{
		$this->f4 = $f4;
		$this->assets = $assets;
		$this->component_manager = $component_manager;
		$this->setDefault();
	}

	public function setMeta($key, $val){ 	
		if(empty($val)) unset($this->meta[$key]);
		$this->meta[$key] = $val;
	}

	public function getMeta($key, $def = ''){
		return !empty($this->meta[$key])?$this->meta[$key]:$def;
	}

	public function setLayout(string $layout){ 	
		$this->layout = $layout;
	}

	public function getLayout(){
		return $this->layout;
	}

	public function setDefault(){
		$this->meta = array(
			'title' => 'Сайт',
			'description' => '',
		);
		$this->f4->set('content.header','/include/header.php');
		$this->f4->set('content.footer','/include/footer.php');
	}

	public function showHead(){
		echo self::BUFFER['head'];
	}

	public function showFooter(){
		echo self::BUFFER['footer'];
	}

	public function renderBuffer($html, $file){
		$headHtml = '';
		$footerHtml = '';
		$headHtml .= $this->generateMeta();
		$headHtml .= $this->assets->renderCss();
		$headHtml .= $this->assets->renderJs();
		$buffer = [];
		$buffer[] = '/'.self::BUFFER['head'].'/';
		$buffer[] = '/'.self::BUFFER['footer'].'/';
		$html = $this->injectAlerts($html);
		return preg_replace($buffer, [$headHtml, $footerHtml], $html);
	}

	public function render(array $arParams = []){
		$template = template();
		$template->afterrender([$this, 'renderBuffer'],$this->layout);
        return $template->render($this->layout,$arParams);
	}

	public function content(string $val){
		$content = $this->f4->get('content');
		if($val && is_array($content) && isset($content[$val])){
			return $content[$val];
		} else {
			$this->f4->set('block_not_found',$val);
			return '/include/block404.php';
		}
	}

	public function setContent($key,$val){
		$key = preg_replace('/[^a-zA-Z_-]*/', '', $key);
		if(!empty($key)){
			$this->f4->set('content.'.$key,$val);
		}
	}

	public function generateMeta(){
		$text = [];
		if(!empty($this->meta['description'])){
			$text[] = '<meta name="description" content="'.$this->meta['description'].'">';
		}
		return implode("\n", $text);
	}

	/** Собрать HTML алертов строкой */
    private function getAlertsHtml(): string
    {
        $alerts = (array) $this->alerts;
        $alerts = array_values(array_filter($alerts, static fn($a) => (string)$a !== ''));

        if (!$alerts) return '';

        $chunks = [];
        foreach ($alerts as $a) {
            // алерты уже экранированы в Assets alert(); но на всякий — ещё раз
            $chunks[] = '<div class="app_alert">'. $a .'</div>';
        }
        return '<div class="app_alerts">'. implode('', $chunks) .'</div>';
    }

    private function injectAlerts(string $html): string
    {
        $alerts = $this->getAlertsHtml();
        if ($alerts === '') return $html;

        // один раз, сразу после <body ...>
        return preg_replace('/(<body\b[^>]*>)/i', '$1' . $alerts, $html, 1);
    }

    public function addAlert($msg) : void
    {
        $this->alerts[] = $msg;
    }
}