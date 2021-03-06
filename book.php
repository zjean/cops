<?php
/**
 * COPS (Calibre OPDS PHP Server) class file
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Sébastien Lucas <sebastien@slucas.fr>
 */

require_once('base.php');
require_once('serie.php');
require_once('author.php');
require_once('tag.php');

class Book extends Base {
    const ALL_BOOKS_UUID = "urn:uuid";
    const ALL_BOOKS_ID = "calibre:books";
    const ALL_RECENT_BOOKS_ID = "calibre:recentbooks";
    const BOOK_COLUMNS = "books.id as id, books.title as title, text as comment, path, timestamp, pubdate, series_index, uuid";
    
    public $id;
    public $title;
    public $timestamp;
    public $pubdate;
    public $path;
    public $uuid;
    public $relativePath;
    public $seriesIndex;
    public $comment;
    public $authors = NULL;
    public $serie = NULL;
    public $tags = NULL;
    public $format = array ();
    public static $mimetypes = array(
        'epub'   => 'application/epub+zip',
        'mobi'   => 'application/x-mobipocket-ebook',
        'pdf'    => 'application/pdf'
    );
    
    public function __construct($line) {
        global $config;
        $this->id = $line->id;
        $this->title = $line->title;
        $this->timestamp = strtotime ($line->timestamp);
        $this->pubdate = strtotime ($line->pubdate);
        $this->path = $config['calibre_directory'] . $line->path;
        $this->relativePath = $line->path;
        $this->seriesIndex = $line->series_index;
        $this->comment = $line->comment;
        $this->uuid = $line->uuid;
    }
        
    public function getEntryId () {
        return self::ALL_BOOKS_UUID.":".$this->uuid;
    }
    
    public static function getEntryIdByLetter ($startingLetter) {
        return self::ALL_BOOKS_ID.":letter:".$startingLetter;
    }
    
    public function getTitle () {
        return $this->title;
    }
    
    public function getAuthors () {
        if (is_null ($this->authors)) {
            $this->authors = Author::getAuthorByBookId ($this->id);
        }
        return $this->authors;
    }
    
    public function getAuthorsName () {
        $authorList = null;
        foreach ($this->getAuthors () as $author) {
            if ($authorList) {
                $authorList = $authorList . ", " . $author->name;
            }
            else
            {
                $authorList = $author->name;
            }
        }
        return $authorList;
    }
    
    public function getSerie () {
        if (is_null ($this->serie)) {
            $this->serie = Serie::getSerieByBookId ($this->id);
        }
        return $this->serie;
    }
    
    public function getTags () {
        if (is_null ($this->tags)) {
            $this->tags = array ();
            
            $result = parent::getDb ()->prepare('select tags.id as id, name
                from books_tags_link, tags
                where tag = tags.id
                and book = ?
                order by name');
            $result->execute (array ($this->id));
            while ($post = $result->fetchObject ())
            {
                array_push ($this->tags, new Tag ($post->id, $post->name));
            }
        }
        return $this->tags;
    }
    
    public function getTagsName () {
        $tagList = null;
        foreach ($this->getTags () as $tag) {
            if ($tagList) {
                $tagList = $tagList . ", " . $tag->name;
            }
            else
            {
                $tagList = $tag->name;
            }
        }
        return $tagList;
    }
    
    public function getComment ($withSerie = true) {
        $addition = "";
        $se = $this->getSerie ();
        if (!is_null ($se) && $withSerie) {
            $addition = $addition . "<strong>" . localize("content.series") . "</strong>" . str_format (localize ("content.series.data"), $this->seriesIndex, htmlspecialchars ($se->name)) . "<br />\n";
        }
        if (preg_match ("/<\/(div|p|a)>/", $this->comment))
        {
            return $addition . str_replace ("<br>", "<br />", $this->comment);
        }
        else
        {
            return $addition . htmlspecialchars ($this->comment);
        }
    }
    
    public function getFilePath ($extension, $relative = false)
    {
        if ($handle = opendir($this->path)) {
            while (false !== ($file = readdir($handle))) {
                if (preg_match ('/' . $extension . '$/', $file)) {
                    if ($relative)
                    {
                        return $this->relativePath."/".$file;
                    }
                    else
                    {
                        return $this->path."/".$file;
                    }
                }
            }
        }
        return NULL;
    }
    
    public function getLinkArray ()
    {
        global $config;
        $linkArray = array();
        if ($handle = opendir($this->path)) {
            while (false !== ($file = readdir($handle))) {
                if (preg_match ('/jpg$/', $file)) {
                    if (preg_match ('/^\//', $config['calibre_directory']))
                    {
                        array_push ($linkArray, new Link ("fetch.php?id=$this->id", "image/jpeg", Link::OPDS_IMAGE_TYPE));
                    }
                    else
                    {
                        array_push ($linkArray, new Link (str_replace('%2F','/',rawurlencode ($this->path."/".$file)), "image/jpeg", Link::OPDS_IMAGE_TYPE));
                    }
                    $height = "50";
                    if (preg_match ('/feed.php/', $_SERVER["SCRIPT_NAME"])) {
                        $height = $config['cops_opds_thumbnail_height'];
                    }
                    else
                    {
                        $height = $config['cops_html_thumbnail_height'];
                    }
                    array_push ($linkArray, new Link ("fetch.php?id=$this->id&height=" . $height, "image/jpeg", Link::OPDS_THUMBNAIL_TYPE));
                }
                foreach (self::$mimetypes as $ext => $mime)
                {
                    if (preg_match ('/'. $ext .'$/', $file)) {
                        $this->format [$ext] = $file;
                        if (preg_match ('/^\//', $config['calibre_directory']))
                        {
                            array_push ($linkArray, new Link ("fetch.php?id=$this->id&type=" . $ext, $mime, Link::OPDS_ACQUISITION_TYPE, "Download"));
                        }
                        else
                        {
                            array_push ($linkArray, new Link (str_replace('%2F','/',rawurlencode ($this->path."/".$file)), $mime, Link::OPDS_ACQUISITION_TYPE, "Download"));
                        }
                    }
                }
            }
        }
        
        foreach ($this->getAuthors () as $author) {
            array_push ($linkArray, new LinkNavigation ($author->getUri (), "related", str_format (localize ("bookentry.author"), localize ("splitByLetter.book.other"), $author->name)));
        }
        
        $serie = $this->getSerie ();
        if (!is_null ($serie)) {
            array_push ($linkArray, new LinkNavigation ($serie->getUri (), "related", str_format (localize ("content.series.data"), $this->seriesIndex, $serie->name)));
        }
        
        return $linkArray;
    }

    
    public function getEntry () {    
        return new EntryBook ($this->getTitle (), $this->getEntryId (), 
            $this->getComment (), "text/html", 
            $this->getLinkArray (), $this);
    }

    public static function getCount() {
        global $config;
        $nBooks = parent::getDb ()->query('select count(*) from books')->fetchColumn();
        $result = array();
        $entry = new Entry (localize ("allbooks.title"), 
                          self::ALL_BOOKS_ID, 
                          str_format (localize ("allbooks.alphabetical"), $nBooks), "text", 
                          array ( new LinkNavigation ("?page=".parent::PAGE_ALL_BOOKS)));
        array_push ($result, $entry);
        $entry = new Entry (localize ("recent.title"), 
                          self::ALL_RECENT_BOOKS_ID, 
                          str_format (localize ("recent.list"), $config['cops_recentbooks_limit']), "text", 
                          array ( new LinkNavigation ("?page=".parent::PAGE_ALL_RECENT_BOOKS)));
        array_push ($result, $entry);
        return $result;
    }
        
    public static function getBooksByAuthor($authorId) {
        $result = parent::getDb ()->prepare('select ' . self::BOOK_COLUMNS . '
from books_authors_link, books left outer join comments on comments.book = books.id
where books_authors_link.book = books.id
and author = ?
order by pubdate');
        $entryArray = array();
        $result->execute (array ($authorId));
        while ($post = $result->fetchObject ())
        {
            $book = new Book ($post);
            array_push ($entryArray, $book->getEntry ());
        }
        return $entryArray;
    }

    
    public static function getBooksBySeries($serieId) {
        $result = parent::getDb ()->prepare('select ' . self::BOOK_COLUMNS . '
from books_series_link, books left outer join comments on comments.book = books.id
where books_series_link.book = books.id and series = ?
order by series_index');
        $entryArray = array();
        $result->execute (array ($serieId));
        while ($post = $result->fetchObject ())
        {
            $book = new Book ($post);
            array_push ($entryArray, $book->getEntry ());
        }
        return $entryArray;
    }
    
    public static function getBooksByTag($tagId) {
        $result = parent::getDb ()->prepare('select ' . self::BOOK_COLUMNS . '
from books_tags_link, books left outer join comments on comments.book = books.id
where books_tags_link.book = books.id and tag = ?
order by sort');
        $entryArray = array();
        $result->execute (array ($tagId));
        while ($post = $result->fetchObject ())
        {
            $book = new Book ($post);
            array_push ($entryArray, $book->getEntry ());
        }
        return $entryArray;
    }
    
    public static function getBookById($bookId) {
        $result = parent::getDb ()->prepare('select ' . self::BOOK_COLUMNS . '
from books left outer join comments on book = books.id
where books.id = ?');
        $entryArray = array();
        $result->execute (array ($bookId));
        while ($post = $result->fetchObject ())
        {
            $book = new Book ($post);
            return $book;
        }
        return NULL;
    }
    
    public static function getBooksByQuery($query) {
        $result = parent::getDb ()->prepare("select " . self::BOOK_COLUMNS . "
from books left outer join comments on book = books.id
where exists (select null from authors, books_authors_link where book = books.id and author = authors.id and authors.name like ?)
or title like ?");
        $entryArray = array();
        $queryLike = "%" . $query . "%";
        $result->execute (array ($queryLike, $queryLike));
        while ($post = $result->fetchObject ())
        {
            $book = new Book ($post);
            array_push ($entryArray, $book->getEntry ());
        }
        return $entryArray;
    }
    
    public static function getAllBooks() {
        $result = parent::getDb ()->query("select substr (upper (sort), 1, 1) as title, count(*) as count
from books
group by substr (upper (sort), 1, 1)
order by substr (upper (sort), 1, 1)");
        $entryArray = array();
        while ($post = $result->fetchObject ())
        {
            array_push ($entryArray, new Entry ($post->title, Book::getEntryIdByLetter ($post->title), 
                str_format (localize("bookword.many"), $post->count), "text", 
                array ( new LinkNavigation ("?page=".parent::PAGE_ALL_BOOKS_LETTER."&id=".$post->title))));
        }
        return $entryArray;
    }
    
    public static function getBooksByStartingLetter($letter) {
        $result = parent::getDb ()->prepare('select ' . self::BOOK_COLUMNS . '
from books left outer join comments on book = books.id
where upper (books.sort) like ?');
        $entryArray = array();
        $queryLike = $letter . "%";
        $result->execute (array ($queryLike));
        while ($post = $result->fetchObject ())
        {
            $book = new Book ($post);
            array_push ($entryArray, $book->getEntry ());
        }
        return $entryArray;
    }

    
    public static function getAllRecentBooks() {
        global $config;
        $result = parent::getDb ()->query("select " . self::BOOK_COLUMNS . "
from books left outer join comments on book = books.id
order by timestamp desc limit " . $config['cops_recentbooks_limit']);
        $entryArray = array();
        while ($post = $result->fetchObject ())
        {
            $book = new Book ($post);
            array_push ($entryArray, $book->getEntry ());
        }
        return $entryArray;
    }

}
?>