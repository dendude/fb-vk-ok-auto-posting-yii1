<?php

/**
 * сканирование сайтов на наличие новостей для автопостинга в группы соцсетей
 * @author DenDude [denis.kravtsov.1986@mail.ru]
 */
class NewsScanCommand extends CConsoleCommand {

    protected $eol = "\n";

    private $_photoPath;

    private $_news = [];

    private $_providerId;
    private $_providerData;

    private $_startTime;
    private $_finishTime;
    private $_photosTime;

    private $_startMicroTime;
    private $_finishMicroTime;
    private $_photosMicroTime;

    private $_errors = [];

    public function init() {

        $this->_photoPath = Yii::app()->basePath . '/../assets/';

        // проверка настроек времени для сканирования
        $settings = Settings::model()->last()->find();
        if (!in_array(date('G'), $settings->scan_times)) {
            if (!in_array('-v', $_SERVER['argv'])) {
                echo 'Break hour (' . date('G') . '), only: ' . implode('; ', $settings->scan_times) . $this->eol;
                Yii::app()->end();
            }
        }

        Yii::import('ext.simplehtmldom.*');
        require_once('simple_html_dom.php');
    }

    protected function beforeAction($action, $params) {

        $this->_startTime = time();
        $this->_startMicroTime = Normalize::getMicrotime();

        echo "Scan Start: " . date('Y-m-d H:i:s', $this->_startTime) . $this->eol;

        return parent::beforeAction($action, $params);
    }

    protected function afterAction($action, $params, $exitCode = 0) {

        $this->_finishTime = time();
        $this->_finishMicroTime = Normalize::getMicrotime();

        echo "Scan Finish: " . date('Y-m-d H:i:s', $this->_finishMicroTime) . $this->eol;

        if ($this->_errors) {
            echo 'Errors: ' . print_r($this->_errors, 1);
        }

        // сохранение записи скана
        $scan = new Scan;
        $scan->provider_id = $this->_providerId;
        $scan->start_time = $this->_startTime;
        $scan->finish_time = $this->_finishTime;
        $scan->news_amount = 0;
        $scan->errors = !empty($this->_errors) ? print_r($this->_errors, 1) : '';

        // сохраняем новости если есть
        if (!empty($this->_news)) {

            foreach ($this->_news AS $newInfo) {

                // если есть в нашей базе - пропускаем
                $hasNews = News::model()
                         ->byProvider($this->_providerId)
                         ->byNews($newInfo['id'])
                         ->exists();

                if ($hasNews) continue;

                $new = new News;
                $new->provider_id = $this->_providerId;
                $new->new_id = $newInfo['id'];
                $new->new_title = $newInfo['title'];
                $new->new_about = $newInfo['about'];
                $new->new_link = $newInfo['link'];
                $new->new_image = $newInfo['image'];
                $new->new_our_image = '';
                $new->new_vk_image = '';

                // скачивание фото
                if (!empty($newInfo['image'])) {
                    $downloadPhoto = $this->downloadImage($this->_providerData->site . $newInfo['image']);
                    if ($downloadPhoto) {
                        $new->new_our_image = $downloadPhoto;
                        $new->new_vk_image = $this->sendImageToVK($newInfo, $downloadPhoto);
                    }
                }
                
                $new->save();
                $scan->news_amount++;
            }
        }

        echo 'New news: ' . $scan->news_amount . $this->eol;

        if ($scan->news_amount) {
            $this->_photosTime = time();
            $this->_photosMicroTime = Normalize::getMicrotime();
        } else {
            $this->_photosTime = $this->_finishTime;
            $this->_photosMicroTime = $this->_finishMicroTime;
        }

        echo "Total Time: " . round($this->_photosMicroTime - $this->_startMicroTime, 2) . $this->eol;

        $scan->photos_time = round($this->_photosMicroTime - $this->_finishMicroTime, 2);
        $scan->scan_time = round($this->_finishMicroTime - $this->_startMicroTime, 2);
        $scan->save();

        return parent::afterAction($action, $params);
    }

    private function iconvDecode($str) {
        return iconv("windows-1251", "UTF-8", $str);
    }

    // отправка фото на сервер ВК
    private function sendImageToVK($newInfo, $downloadPhoto) {

        $vkUrl = 'https://api.vk.com/method/';

        echo "Sending photo to VK: " . $downloadPhoto . $this->eol;

        try {

            // запрос сервера для отправки фото
            $url = $vkUrl . "photos.getWallUploadServer?group_id=" . $this->_providerData->group_vk_id .
                                                  "&access_token=" . $this->_providerData->app_vk_token;

            $resp = json_decode(file_get_contents($url));

            if (!empty($resp->response->upload_url)) {

                $files = ['file1' => '@' . $this->_photoPath . $downloadPhoto];

                // отправка фото на сервер вк			
                $ch = curl_init($resp->response->upload_url);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $files);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                $result = json_decode(curl_exec($ch));
                curl_close($ch);

                if (!empty($result->photo)) {

                    sleep(1);
                    // сохранение фото для дальнейшего использования
                    $url = $vkUrl . "photos.saveWallPhoto?group_id=" . $this->_providerData->group_vk_id .
                                                           "&photo=" . $result->photo .
                                                          "&server=" . (int)$result->server .
                                                            "&hash=" . $result->hash .
                                                    "&access_token=" . $this->_providerData->app_vk_token;
                    $photo = json_decode(file_get_contents($url));

                    if (!empty($photo->response[0]->id)) {
                        return $photo->response[0]->id;
                    } else {
                        throw new Exception('Error save vk photo: ' . print_r($photo, 1));
                    }
                } else {
                    throw new Exception('Error post vk photo: ' . print_r($result, 1));
                }
            } else {
                throw new Exception('Error get vk photo server: ' . print_r($resp, 1));
            }
        } catch (Exception $e) {
            $this->_errors[] = 'Error get vk photo server: ' . $e->getMessage();
        }

        return '';
    }

    public function actionScanRun($providerId) {

        switch ($providerId) {

            case 1:
                $this->actionScanJodTerapia();
                break;
                
            case 2:
                $this->actionScanChastnyjDomPrestarelyh();
                break;
                
            case 3:
                $this->actionScanPatronageRu();
                break;
                
            case 5:
                $this->actionMakeBody();
                break;
        }
    }

    /**
     * скан проекта РадиоЙодТерапия
     * @return void
     */
    public function actionScanJodTerapia() {

        $this->_providerId = 1;
        $pData = Providers::model()->findByPk($this->_providerId);

        if (!$pData) {
            $this->_errors[] = 'Провайдер новостей не найден: ' . $this->_providerId;
            return false;
        } else {
            $this->_providerData = $pData;
        }

        echo "Name: " . $pData->name . $this->eol;
        echo "Scanpage: " . $pData->scanpage . $this->eol;

        // загрузка страницы		
        $html = new simple_html_dom();
        $html->load_file($pData->scanpage);
        // парсинг новостей
        $news = $html->find('.news_list .item_left');

        if (!empty($news)) {

            foreach ($news AS $k => $newElem) {

                $a = $newElem->find('.news_title a', 0);
                $link = $a->href;

                preg_match('/^\/[\w]+\/(\d+)\/$/', $link, $matches); // вытягиваем ид новости из ссылки вида: /news/123/

                $newInfo = [
                    'id'    => (int)$matches[1],
                    'link'  => $link,
                    'title' => preg_replace('/\.$/', '', $this->iconvDecode($a->innertext)),
                    // декодируем и удаляем точку в конце
                    'about' => urldecode($html->find('.news_ent_text', 0)->plaintext),
                    'image' => $newElem->find('.pic img', 0)->src,
                ];

                $page = new simple_html_dom();
                $page->load_file($pData->site . $newInfo['link']);
                if ($page->find('div#page img', 0)) {
                    $newInfo['image'] = $page->find('div#page img', 0)->src;
                }

                // заполняем массив найденных новостей
                $this->_news[] = $newInfo;
            }
        } else {
            $this->_errors[] = 'Новости не найдены';
        }

        // очистка
        $html->clear();
    }

    /**
     * скан проекта Частный дом престарелых
     * @return bool
     */
    public function actionScanChastnyjDomPrestarelyh() {

        $this->_providerId = 2;
        $pData = Providers::model()->findByPk($this->_providerId);

        if (!$pData) {
            $this->_errors[] = 'Провайдер новостей не найден: ' . $this->_providerId;
            return false;
        } else {
            $this->_providerData = $pData;
        }

        echo "Name: " . $pData->name . PHP_EOL;
        echo "Scanpage: " . $pData->scanpage . PHP_EOL;

        // загрузка страницы		
        $html = new simple_html_dom();
        $html->load_file($pData->scanpage);
        // парсинг новостей
        $news = $html->find('div#content div.news-item');

        if (!empty($news)) {
            foreach ($news AS $k => $newElem) {

                $about = $html->find('.news-item-about p', 0);
                $about_a = $about->find('a', 0);
                $about_a->outertext = '';

                $newInfo = [
                    'id'    => $html->find('.news-item-about', 0)->{'data-id'},
                    'link'  => $newElem->find('a', 0)->href,
                    'title' => $html->find('.news-item-about h2', 0)->innertext,
                    'image' => preg_replace('/(_m\d+)/i', '', $newElem->find('.news-item-photo img', 0)->src),
                    'about' => $about->innertext,
                ];

                // заполняем массив найденных новостей
                // если есть в нашей базе - пропускаем
                $this->_news[] = $newInfo;
            }
        } else {
            $this->_errors[] = 'Новости не найдены';
        }

        // очистка
        $html->clear();
    }

    /**
     * скан проекта ПатронажРу
     * @return void
     */
    public function actionScanPatronageRu() {

        $this->_providerId = 3;
        $pData = Providers::model()->findByPk($this->_providerId);

        if (!$pData) {
            $this->_errors[] = 'Провайдер новостей не найден: ' . $this->_providerId;
            return false;
        } else {
            $this->_providerData = $pData;
        }

        echo "Name: " . $pData->name . $this->eol;
        echo "Scanpage: " . $pData->scanpage . $this->eol;

        // загрузка страницы		
        $html = new simple_html_dom();
        $html->load_file($pData->scanpage);
        // парсинг новостей
        $news = $html->find('div.news_title');

        if (!empty($news)) {
            foreach ($news AS $k => $newElem) {

                $link = $newElem->find('a', 0)->href;
                preg_match('/^\/[\w]+\/(\d+)\/$/', $link, $matches); // вытягиваем ид новости из ссылки вида: /news/123/

                $newInfo = [
                    'id'    => (int)$matches[1],
                    'link'  => $link,
                    'title' => preg_replace('/\.$/', '', $this->iconvDecode($newElem->find('a', 0)->innertext)),
                    // декодируем и удаляем точку в конце
                    'about' => urldecode($html->find('div.news_ent_text', $k)->plaintext),
                    'image' => $html->find('td.page_content', 0)->find('img', $k)->src,
                ];

                // заполняем массив найденных новостей
                $this->_news[] = $newInfo;
            }
        } else {
            $this->_errors[] = 'Новости не найдены';
        }

        // очистка
        $html->clear();
    }

    /**
     * скан проекта норма-веса.рф
     * @return bool
     */
    public function actionMakeBody() {

        $this->_providerId = 5;
        $pData = Providers::model()->findByPk($this->_providerId);

        if (!$pData) {
            $this->_errors[] = 'Провайдер новостей не найден: ' . $this->_providerId;
            return false;
        } else {
            $this->_providerData = $pData;
        }

        echo "Name: " . $pData->name . $this->eol;
        echo "Scanpage: " . $pData->scanpage . $this->eol;

        // загрузка страницы
        $html = new simple_html_dom();
        $html->load_file($pData->scanpage);
        // парсинг новостей
        $news = $html->find('div.news-list div.news-list-item');

        if (!empty($news)) {
            foreach ($news AS $k => $newElem) {

                $newInfo = [
                    'id'    => $newElem->find('.news-details', 0)->{'data-id'},
                    'link'  => $newElem->find('.news-details', 0)->href,
                    'title' => $newElem->find('.news-title', 0)->innertext,
                    'about' => $newElem->find('.news-about', 0)->innertext,
                    'image' => null,
                ];

                usleep(250000);
                $page = new simple_html_dom();
                $page->load_file($pData->site . $newInfo['link']);

                if ($page->find('div.page-article')) {
                    $newInfo['title'] = $page->find('h1.page-title', 0)->innertext;

                    if ($page->find('div.page-article img')) {
                        $newInfo['image'] = $page->find('div.page-article img', 0)->src;
                    }
                }

                // заполняем массив найденных новостей
                $this->_news[] = $newInfo;
            }
        } else {
            echo $html->innertext;
            $this->_errors[] = 'Новости не найдены';
        }

        // очистка
        $html->clear();
    }

    /**
     * загрузка фото
     */
    private function downloadImage($url) {

        $content = file_get_contents($url);
        $newName = uniqid() . mt_rand(1, 9999) . '.jpg';
        $fp = fopen($this->_photoPath . $newName, "w");

        if ($fp && $content) {
            fwrite($fp, $content);
            fclose($fp);
            return $newName;
        } else {
            $this->_errors[] = 'Ошибка скачивания фото с сайта поставщика: ' . $url;
            return false;
        }
    }
}
