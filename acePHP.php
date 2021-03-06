<?php
/**
 * Демон трансляции торрент-тв.
 * Для запуска потока нужно обратиться к демону по http://<host>:8000/pid/<pid>/<Stream Name>
 * Например ссылка трансляции канала 2x2 будет выглядеть так
 * http://127.0.0.1:8000/pid/0d2137fc5d44fa9283b6820973f4c0e017898a09/2x2
 * <Stream Name> нужен для отображения в ncurses интерфейсе
 *
 * Для работы требует PHP с pecl-расширением ncurses и сервер AceStream
 * Поддерживает подключение множества клиентов к одной трансляции.
 * Поддерживает воспроизведение .torrent файлов с возможностью перемотки и
 *	просмотра с заданного места
 * Рекомендованные опции запуска AceStream
 * --client-console --live-cache-size 200000000 --upload-limit 1000 --max-upload-slots 10 --live-buffer 45
 *
 * @author	mexxval
 * @link	http://blog.sci-smart.ru
 */

// TODO
// web-интерфейс, можно кстати через тот же порт 8000
// админская навигация по трансляциям в ncurses-UI и закрытие вручную
// ace может можно как то пнуть, чтоб не буферизовал так долго. буфер настроить поменьше или START сказать
//	[не похоже, что такое возможно. однако при скорости интернет-канала 48Мбит фильм стартует за 7-15 сек]
// + memory + cpu usage, uptime и другая статистика
// + вывести для каждого клиента время подключения (uptime)
// нормальное логирование со скроллом
// DLNA? multicast? see pecl extension Gupnp http://php.net/manual/ru/gupnp.installation.php
//		и вообще сетевые SAP потоки замутить, из XBMC-меню чтоб видно было
//		можно поковырять http://www.netlab.linkpc.net/forum/index.php?topic=898.0
// после DLNA/SAP/Multicast внедрить управление торрентами. чтобы из XBMC было видно, сколько осталось качаться
// настроить хедеры: 
//	1. хром вероятно можно заставить показывать видео прямо на странице, если дать правильный хедер
//	2. перещелкивание PgUp/PgDn с пульта ТВ приводит к ошибке "Не удается найти след.файл", возможно тоже получится поправить
// таймаут операций при недоступном torrent-tv.ru: 
//   висит на searching pid, authorizing, при этом других клиентов даже не обрабатывает
// на XBMC узлы можно уведомлениями слать фидбек от демона (tcp 9090 jsonrpc)
//		+реконнект, упала скорость, нет сидов, +не удалось запустить PID, +упал инет, трансляция мертва (Down не растет)
//		необходимость и информативность уведомлений можно задавать в параметре урла запроса трансляции
// + фильмы стартуют не так охотно. бывает клиент уже отвалился, а тут из ace приходит наконец команда start. 
//		Keep-Alive: header, HTTP/1.1, посмотреть какой HTTP умеет XBMC. в 1.1 коннекты вообще персистентны изначально
//			http://habrahabr.ru/post/184302/
//      надо клиента снова цеплять, используя jsonrpc
//		[помогает на клиенте в advansedsettings.xml проставить advancedsettings/network/curllowspeedtime в 60 сек]
//		может через header Location клиента закольцевать, пока торрент грузится
//		вообще есть еще вариант, запускать фильм не через сам XBMC, а через этот софт. 
//		Пусть прогрузит фильм и потом подцепит XBMC
//		а может вообще держать постоянный коннект и полностью интегрировать управление XBMC<->AcePHProxy
//		к тому же поиграться с EVENT от клиента к движку
// рефакторинг: 
//		+ State-Machine обработчик запросов: пробивка сервера (HEAD,OPTIONS), NowPlaying/Recently Played, запуск видео
//		различные классы трасляций (расширение StreamUnit: запуск файлов, торрентов, live, rtsp),
//		расширение класса клиента (XBMC/обычный, на XBMC уведомления слать например)
// искоренить состояние chk, удалять кэш например, оно мертвого достанет
// кэшировать все видео, а не только последнее


// BUGS
// при ошибке коннекта к ace (нет демона), трансляция не удаляется из списка
// при детаче-аттаче screen и вообще при перерисовке окна (ресайз) stream_select() глючит, клиент может отвалиться
// VLC/Kodi/XBMC чтоб показывали. щас только XBMC кажет, остальные выебываются
// при просмотре фильма или серии в конце, когда плеер уже остановлен, коннект какое то время еще висит, секунд 10
// этот же косяк является причиной еще одного: если пока предыдущий коннект висит открыть следующую серию,
//	то пребуферизация прерывается в момент закрытия висящего коннекта


// решенные проблемы по сокетам:
// - определяется нормальный коннект и дисконнект клиента (realtime)
// - определяется отвалившийся AceServer (realtime)
// - нет необходимости висеть в ожидании ссылки на поток (START http), 
//		готовность ссылки проверяется периодически, не вешая программу и клиентов
// - для торрентов из нескольких видеофайлов (сезон сериала например) выдавать меню (плейлист)
// - TODO определять отвалившийся клиент. тут только по таймауту
// - TODO классы исключений и коды ошибок
// - TODO скачанные фильмы по событию cansave надо переносить в фильмотеку
// - TODO не обрабатывается ситуация с умершим ace, когда он совсем не запущен. бесконечные попытки подключиться
// - TODO если isLive и поток кончился (косяк трансляции, гоняется кэш) - закрывать клиента быстрее. 
//		а то XBMC заипет висеть до таймаута, даже стоп не помогает
// - TODO периодически снимать скриншоты потоков, а еще есть программа передач, 
//		как бы это к XBMC прикрутить, чтобы не приходилось запускать каналы ради "глянуть, что идет"
// - TODO помечать ecoMode в UI
// - TODO реализовать интеграцию с rTorrent. в частности требуется запуск фильма по infohash,
//		rtorrent поможет сделать из магнет-ссылки torrent-файл
//		[пробовал подсунуть такой torrent, ace его не ест. говорит "announce, nodes and announce-list missing"]
// - TODO все хранить на стороне софта, список каналов, папка с торрентами. оформить как подключаемые модули
//		можно подключить разные сайты, torrent-tv.ru, yify-torrent.org, eztv, etc
// - TODO playlist и элементы не отмечаются в XBMC как просмотренные, также хорошо бы им длительность указать


# еще одно торрент-тв, открывать через анонимайзер
# http://torrentstream.tv/browse-znanie-videos-1-date.html

require_once dirname(__FILE__) . '/res/init.php';

$App = AcePHProxy::getInstance();

// мониторим новых клиентов, запускаем для них трансляцию или, если такая запущена, копируем данные из нее
// мониторим дисконнекты и убиваем трансляцию, если клиентов больше нет (пока можно сделать ее вечноживой)
// мониторим проблемы с трансляцией и делаем попытку ее перезапустить в случае чего
while (!$App->isCtrlC_Occured()) {
	$App->tick();
	// увеличение с 20 до 100мс улучшило ситуацию с переполнением клиентских сокетов
	usleep(50000);
}



// AceProxy на кривой урл не выдает понятного для XBMC ответа, тот повторяет попытки открыть урл
// m3u открывается долго, потому как XBMC делает по 2 инфо-запроса: HEAD и Range:0-0, на что тоже не получает внятного ответа
// из-за 2 причин выше остановка потока не отрабатывает нормально (висит, пока не пройдут запросы по всем эл-там плейлиста)
// ссылка критична к /stream.mp4 на хвосте ссылки (/pid/<pid>/stream.mp4)
// для трансляции одного потока на несколько клиентов требует VLC
// при нажатии на эл-т плейлиста XBMC замирает секунды на 3-4, затем идет Подождите, потом только пойдет видео
// иногда, нажав на стоп в момент затыка, приходится долго ждать, пока пойдут данные, чтобы XBMC отвис


// несколько замеров времени старта. рестарт производился методом остановки работающего потока и немедленного запуска снова
// каждый замер длился около 1.5-2 мин
//				  клик .. Подождите .. открытие видео .. пошел звук .. 1-я буф-я старт .. финиш .. обрыв .. время рестарта
// AceProxy	VLC	: 0			5				12				12						50		-		78			22
// videotimeout : 0			4				12				12						16		-		да			28
// 20sec		: 0			5				-				-						-		-		30			23
//				: 0			5				13				13						49		-		78			20
//				: 0			5				13				13						55		-		80			23

// AceProxy		: 0			5				12				12						41		45		-			11
//				: 0			5				13				13						-		-		-			21
//				: 0			5				11				11						43		48		-			11
//				: 0			5				11				11						42		46		-			27 не стартануло
//				: 0			5				12				12						42		45		-			23

// AcePHProxy	: 0			0				4				5						31		46		-			3
//				: 0			0				4				4						53		67		-			20
//				: 0			0				4				4						49		62		-			2
//				: 0			0				5				5						26		42		-			21
//				: 0			0				5				5						54		68		-			19

// после настройки параметров запуска AceStream Engine, запуск по PID, вместо trid. 3min на тест
// AcePHProxy	: 0			0				3				3						-		- не было -			<1; 5
//				: 0			0				3				3						-		- не было -			2
//				: 0			0				2.5				2.5						-		- не было -			1; <1
//			HD	: 0			0				3				3						-		- не было -			<1; <1
//			HD	: 0			0				3.5				3.5						31		36		-			1
// в последнем тесте было 6 пиров всего, может потому проскочила буферизация


