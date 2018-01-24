<?php

/**
 * публикация новостей в соцсети
 * @author DenDude [denis.kravtsov.1986@mail.ru]
 */
class NewsPublishCommand extends CConsoleCommand {

    protected $eol = "\n";
    protected $_errors = [];

    public function init() {

        // проверка настроек времени для публикации
        $settings = Settings::model()->last()->find();
        if (!in_array(date('G'), $settings->publish_times)) {
            if (!in_array('-v', $_SERVER['argv'])) {
                echo 'Break hour (' . date('G') . '), only: ' . implode('; ', $settings->publish_times) . $this->eol;
                Yii::app()->end();
            }
        }
    }

    protected function beforeAction($action, $params) {
        echo "Publish Start" . $this->eol;
        return parent::beforeAction($action, $params);
    }

    protected function afterAction($action, $params, $exitCode = 0) {

        if (!empty($this->_errors)) {
            echo 'Errors published: ' . $this->eol;
            print_r($this->_errors);
        }

        echo "Publish Finish" . $this->eol;

        return parent::afterAction($action, $params);
    }

    // публикация новостей в VK
    public function actionVk() {

        $providers = Providers::model()->findAll();
        foreach ($providers AS $prov) {

            echo $prov->name . $this->eol;

            $news_pub = NewsPublished::model()->byGroup($prov->group_vk_id)->findAll();
            $news_pub_ids = $news_pub ? CHtml::listData($news_pub, 'new_id', 'new_id') : [];

            // отбираем одну неопубликованную в соцсети новость
            $criteria = new CDbCriteria;
            $criteria->addNotInCondition('id', $news_pub_ids);
            $criteria->order = 'id DESC';
            if (!empty($prov->site)) {
                // вытягиваем новость конкретного сайта
                // иначе новости изо всех провайдеров в одну группу
                $criteria->addCondition('provider_id = :prov_id');
                $criteria->params[':prov_id'] = $prov->id;
            }

            $newInfo = News::model()->find($criteria);
            if ($newInfo) {
                $this->publishVK($prov, $newInfo);
            } else {
                echo 'News not found' . $this->eol;
            }
        }
    }

    protected function publishVK($prov, $newInfo) {

        $request = "https://api.vk.com/method/wall.post?owner_id=-" . $prov->group_vk_id .
                                                   "&access_token=" . $prov->app_vk_token .
                                                        "&message=" . urlencode($newInfo->new_title . " \n\n" . $newInfo->new_about . " \n\n" . $newInfo->provider->site . $newInfo->new_link) .
                                                    "&attachments=" . $newInfo->new_vk_image .
                                                    "&from_group=1";
        $resp = json_decode(file_get_contents($request));

        $pub = new NewsPublished;
        $pub->new_id = $newInfo->id;
        $pub->provider_id = $newInfo->provider->id;
        $pub->vk_wall_id = !empty($resp->response->post_id) ? (int)$resp->response->post_id : 0;
        $pub->group_id = $prov->group_vk_id;
        $pub->errors = empty($resp->response->post_id) ? print_r($resp, 1) : '';
        $pub->save();

        if ($pub->id) {
            echo 'Vk new posted: ' . $newInfo->id . $this->eol;
        } else {
            $this->_errors = $pub->getErrors();
        }
    }

    // публикация новостей в VK
    public function actionOk() {

        $providers = Providers::model()->findAll();
        foreach ($providers AS $prov) {

            echo $prov->name . $this->eol;

            $news_pub = NewsPublished::model()->byGroup($prov->ok_group_id)->findAll();
            $news_pub_ids = $news_pub ? CHtml::listData($news_pub, 'new_id', 'new_id') : [];

            // отбираем одну неопубликованную в соцсети новость
            $criteria = new CDbCriteria;
            $criteria->addNotInCondition('id', $news_pub_ids);
            $criteria->order = 'id DESC';
            if (!empty($prov->site)) {
                // вытягиваем новость конкретного сайта
                // иначе новости изо всех провайдеров в одну группу
                $criteria->addCondition('provider_id = :prov_id');
                $criteria->params[':prov_id'] = $prov->id;
            }

            $newInfo = News::model()->find($criteria);
            if ($newInfo) {
                $this->publishOK($prov, $newInfo);
            } else {
                echo 'News not found' . $this->eol;
            }
        }
    }

    protected function publishOK($prov, $newInfo) {

        $request_url = 'https://api.ok.ru/fb.do';

        $data = [
            'application_key' => $prov->ok_app_key,
            'method'          => 'mediatopic.post',
            'access_token'    => $prov->ok_access_token,
            'gid'             => $prov->ok_group_id,
            'type'            => 'GROUP_THEME',
            'application_id'  => $prov->ok_app_id,
            'attachment'      => ['media' => [
                ['type' => 'text', 'text' => $newInfo->new_title . " \n\n" . $newInfo->new_about],
                ['type' => 'link', 'url' => $newInfo->provider->site . $newInfo->new_link],
            ]],
        ];

        $postfix = md5($data['access_token'] . $prov->ok_app_secret_key);

        ksort($data);

        $concat_str = '';
        $request_str = '';
        foreach ($data AS $pk => $pv) {
            // строка запроса
            $request_str .= '&' . ($pk . '=' . (is_array($pv) ? urlencode(json_encode($pv)) : $pv));;
            // токен не участвует в формировании сигнатуры
            if ($pk == 'access_token') continue;
            // строка для сигнатуры
            $concat_str .= ($pk . '=' . (is_array($pv) ? json_encode($pv) : $pv));
        }

        $concat_str .= $postfix;

        $request_str .= '&sig=' . strtolower(md5($concat_str));
        $request_str = trim($request_str, '&');

        $request = $request_url . '?' . $request_str;
        $response = file_get_contents($request);
        if (preg_match('/^("[0-9]+")$/', $response)) {
            $response = (int)str_replace('"', '', $response);
        }

        $pub = new NewsPublished;
        $pub->new_id = $newInfo->id;
        $pub->provider_id = $newInfo->provider->id;
        $pub->ok_wall_id = is_numeric($response) ? (int)$response : 0;
        $pub->group_id = $prov->ok_group_id;
        $pub->errors = !is_numeric($response) ? print_r($response, 1) : '';
        $pub->save();

        if ($pub->id) {
            echo 'OK new posted: ' . $newInfo->id . $this->eol;
        } else {
            $this->_errors = $pub->getErrors();
        }
    }

    public function actionFB() {
        $providers = Providers::model()->findAll();
        foreach ($providers AS $prov) {

            if (empty($prov->app_fb_token)) continue;
            echo $prov->name . $this->eol;

            $news_pub = NewsPublished::model()->byGroup($prov->group_fb_id)->findAll();
            $news_pub_ids = $news_pub ? CHtml::listData($news_pub, 'new_id', 'new_id') : [];

            // отбираем одну неопубликованную в соцсети новость
            $criteria = new CDbCriteria;
            $criteria->addNotInCondition('id', $news_pub_ids);
            $criteria->order = 'id DESC';
            if (!empty($prov->site)) {
                // вытягиваем новость конкретного сайта
                // иначе новости изо всех провайдеров в одну группу
                $criteria->addCondition('provider_id = :prov_id');
                $criteria->params[':prov_id'] = $prov->id;
            }

            $newInfo = News::model()->find($criteria);
            if ($newInfo) {
                $this->publishFB($prov, $newInfo);
            } else {
                echo 'News not found' . $this->eol;
            }
        }
    }

    /**
     * публикация новостей в FB
     *
     * получение токена для публиный страниц
     * 1) https://developers.facebook.com/tools/explorer/719887311462184?method=GET&path=me%2Faccounts&version=v2.7
     * 2) выбираем приложение Новости (ид 719887311462184)
     * 3) выбираем getPageToken и каждую нужную страницу
     *
     * Class FacebookController
     *
     * @package app\commands
     */
    protected function publishFB($prov, $newInfo) {

        Yii::import('ext.facebook.php-sdk-v4.src.Facebook.*');
        require_once('autoload.php');

        $fb = new \Facebook\Facebook([
            'app_id'                => $prov->app_fb_id,
            'app_secret'            => $prov->app_fb_secure,
            'default_graph_version' => 'v2.7',
        ]);

        $res = $fb->post("/{$prov->group_fb_id}/feed", [
            'published' => 1,
            'message'   => $newInfo->new_title . " \n\n" . $newInfo->new_about,
            'link'      => $newInfo->provider->site . $newInfo->new_link,
        ], $prov->app_fb_token);

        if ($body = $res->getBody()) {

            $data = json_decode($body);

            $pub = new NewsPublished;
            $pub->new_id = $newInfo->id;
            $pub->provider_id = $newInfo->provider->id;
            $pub->fb_wall_id = (string)$data->id;
            $pub->group_id = $prov->group_fb_id;
            $pub->errors = '';
            if (!$pub->save()) {
                print_r($pub->getErrors());
            }

            echo 'OK new posted: ' . $newInfo->id . $this->eol;
        } else {
            echo 'News not published' . PHP_EOL;
        }
    }
}
