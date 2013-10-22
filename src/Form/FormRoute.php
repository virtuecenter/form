<?php
namespace Form;

class FormRoute {
	private $separation;
	private $post;
	private $slim;
	private $topic;
	private $response;
	private $field;
	public $cache = false;

	public function __construct ($slim, $form, $field, $post, $separation, $topic, $response) {
		$this->slim = $slim;
		$this->post = $post;
		$this->form = $form;
		$this->separation = $separation;
		$this->topic = $topic;
		$this->response = $response;
		$this->field = $field;
	}

	public function cacheSet ($cache) {
		$this->$cache = $cache;
	}

	public function json () {
		$this->slim->get('/json-form/:form(/:id)', function ($form, $id=false) {
			if (isset($_GET['id']) && $id === false) {
				$id = $_GET['id'];
			} 
		    $formObject = $this->form->factory($form, $id);
		    $head = null;
		    $tail = null;
		    if (isset($_GET['pretty'])) {
		        $head = '<html><head></head><body style="margin:0; border:0; padding: 0"><textarea wrap="off" style="overflow: auto; margin:0; border:0; padding: 0; width:100%; height: 100%">';
		        $tail = '</textarea></body></html>';
		    } elseif (isset($_GET['callback'])) {
		    	$head = $_GET['callback'] . '(';
		    	$tail = ');';
		   	}
		    echo $head, $this->form->json($formObject, $id), $tail;
		});
	}

	public function app ($root) {
		if (!empty($this->cache)) {
			$collections = $this->cache;
		} else {
			$cacheFile = $root . '/../forms/cache.json';
			if (!file_exists($cacheFile)) {
				return;
			}
			$forms = (array)json_decode(file_get_contents($cacheFile), true);
		}
		if (!is_array($forms)) {
			return;
		}
	    foreach ($forms as $form) {
	    	$this->slim->get('/form/' . $form . '(/:id)', function ($id=false) use ($form) {
                if ($id === false) {
                	$this->separation->layout('form-' . $form)->template()->write($this->response->body);
                } else {
                	$this->separation->layout('form-' . $form)->args($form, ['id' => $id])->template()->write($this->response->body);
                }
            })->name('form ' . $form);
            $this->slim->post('/form/' . $form . '(/:id)', function ($id=false) use ($form) {
            	$formObject = $this->form->factory($form, $id);
            	if ($id === false) {
            		if (isset($this->post->{$formObject->marker}['id'])) {
            			$id = $this->post->{$formObject->marker}['id'];
            		} else {
            			throw new \Exception('ID not supplied in post.');
            		}
            	}
               	$event = [
            		'dbURI' => $formObject->storage['collection'] . ':' . $id,
            		'formMarker' => $formObject->marker
            	];
            	if (!$this->form->validate($formObject)) {
            		$this->form->responseError();
            		return;
            	}
            	$this->form->sanitize($formObject);
            	$this->topic->publish('form-' . $form . '-save', $event);
            	if ($this->post->statusCheck() == 'saved') {
            		$this->form->responseSuccess($formObject);
            	} else {
            		$this->form->responseError();	
            	}
            });
	    }
	}

	private static function stubRead ($name, &$collection, $url, $root) {
		return str_replace(['{{$url}}', '{{$plural}}', '{{$singular}}'], [$url, $collection['p'], $collection['s']], $data);
	}

	public function build ($root, $url) {
		$cache = [];
		$dirFiles = glob($root . '/../forms/*.php');
		foreach ($dirFiles as $form) {
			$class = basename($form, '.php');
			$cache[] = $class;
		}
		$json = json_encode($cache, JSON_PRETTY_PRINT);
		file_put_contents($root . '/../forms/cache.json', $json);
		foreach ($cache as $form) {
			$filename = $root . '/layouts/forms/' . $form . '.html';
			if (!file_exists($filename)) {
				$data = file_get_contents($root . '/../vendor/virtuecenter/build/static/form.html');
				$data = str_replace(['{{$form}}'], [$form], $data);
				file_put_contents($filename, $data);
			}
			$filename = $root . '/partials/forms/' . $form . '.hbs';
			if (!file_exists($filename)) {
				$data = file_get_contents($root . '/../vendor/virtuecenter/build/static/form.hbs');
				$formObject = $this->form->factory($form);
				ob_start();
				echo '
<form class="ui form segment" data-xhr="true" data-marker="contact" method="post">', "\n";

				foreach ($formObject->fields as $field) {
					echo '
    <div class="field">
        <label>', ucwords(str_replace('_', ' ', $field['name'])), '</label>
        <div class="ui left labeled input">
            {{{', $field['name'], '}}}
            <div class="ui corner label">
            	<i class="icon asterisk"></i>
            </div>
        </div>
    </div>', "\n";
				}
				echo '
    {{{id}}}
	<input type="submit" class="ui blue submit button" value="Submit" />
</form>';
				$generated = ob_get_clean();
				$data = str_replace(['{{$form}}', '{{$generated}}'], [$form, $generated], $data);
				file_put_contents($filename, $data);
			}
			$filename = $root . '/../app/forms/' . $form . '.yml';
			if (!file_exists($filename)) {
				$data = file_get_contents($root . '/../vendor/virtuecenter/build/static/app-form.yml');
				$data = str_replace(['{{$form}}', '{{$url}}'], [$form, $url], $data);
				file_put_contents($filename, $data);
			}
		}
		return $json;
	}
}