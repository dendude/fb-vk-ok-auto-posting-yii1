# fb-vk-od-posting
Автопостинг в соцсети ВКонтакте, Фейсбук, Одноклассники

1. Подготовительные шаги:
    * Скачиваем [Yii 1.1.19](https://github.com/yiisoft/yii/releases/download/1.1.19/yii-1.1.19.5790cb.tar.gz)
    * Распаковываем архив и настраиваем фреймворк под консольное приложение

2. Файлы данного репозитория помещаем в `project_path/protected/commands`

---

#### Настройка crontab на сервере `crontab -e`

Так как скрипт работает не напрямую с базой данных, а парсит новости с нужных сайтов, 
запускаем парсер для каждого проекта отдельно, где providerId - идентификатор сайта в базе данных

```shell
1 * * * * cd ~/www/site.com && php cron.php NewsScan scanRun --providerId=1
2 * * * * cd ~/www/site.com && php cron.php NewsScan scanRun --providerId=2
3 * * * * cd ~/www/site.com && php cron.php NewsScan scanRun --providerId=3
4 * * * * cd ~/www/site.com && php cron.php NewsScan scanRun --providerId=5
```

Публикация в каждую соцсеть тоже происходит отдельными процессами.

```shell
5 * * * * cd ~/www/site.com && php cron.php NewsPublish vk
6 * * * * cd ~/www/site.com && php cron.php NewsPublish ok
7 * * * * cd ~/www/site.com && php cron.php NewsPublish fb
```

#### Тест скриптов через `console`

Чтобы избежать ошибок, связанных с переменными окружения, воспользуемся полным путем к интерпретатору `php`:

```shell
$ which php # получим что-то вроде /usr/bin/php
# запуск скриптов
$ cd ~/project_path
$ php cron.php NewsScan scanRun --providerId=1
$ /usr/bin/php cron.php NewsPublish vk
```
