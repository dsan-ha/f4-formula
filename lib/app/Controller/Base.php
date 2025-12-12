<?

namespace App\Controller;

use Core\Assets;

final class Base extends ControllerBase
{
    public function index($req, $res, $params)
    {
        return $this->render($res,'pages/index.php');
    }
}