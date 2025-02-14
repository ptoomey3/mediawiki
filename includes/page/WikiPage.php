<?php
/**
 * Base representation for a MediaWiki page.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

/**
 * Abstract class for type hinting (accepts WikiPage, Article, ImagePage, CategoryPage)
 */
interface Page {
}

/**
 * Class representing a MediaWiki article and history.
 *
 * Some fields are public only for backwards-compatibility. Use accessors.
 * In the past, this class was part of Article.php and everything was public.
 */
class WikiPage implements Page, IDBAccessObject {
	// Constants for $mDataLoadedFrom and related

	/**
	 * @var Title
	 */
	public $mTitle = null;

	/**@{{
	 * @protected
	 */
	public $mDataLoaded = false;         // !< Boolean
	public $mIsRedirect = false;         // !< Boolean
	public $mLatest = false;             // !< Integer (false means "not loaded")
	/**@}}*/

	/** @var stdClass Map of cache fields (text, parser output, ect) for a proposed/new edit */
	public $mPreparedEdit = false;

	/**
	 * @var int
	 */
	protected $mId = null;

	/**
	 * @var int One of the READ_* constants
	 */
	protected $mDataLoadedFrom = self::READ_NONE;

	/**
	 * @var Title
	 */
	protected $mRedirectTarget = null;

	/**
	 * @var Revision
	 */
	protected $mLastRevision = null;

	/**
	 * @var string Timestamp of the current revision or empty string if not loaded
	 */
	protected $mTimestamp = '';

	/**
	 * @var string
	 */
	protected $mTouched = '19700101000000';

	/**
	 * @var string
	 */
	protected $mLinksUpdated = '19700101000000';

	/**
	 * Constructor and clear the article
	 * @param Title $title Reference to a Title object.
	 */
	public function __construct( Title $title ) {
		$this->mTitle = $title;
	}

	/**
	 * Create a WikiPage object of the appropriate class for the given title.
	 *
	 * @param Title $title
	 *
	 * @throws MWException
	 * @return WikiPage Object of the appropriate type
	 */
	public static function factory( Title $title ) {
		$ns = $title->getNamespace();

		if ( $ns == NS_MEDIA ) {
			throw new MWException( "NS_MEDIA is a virtual namespace; use NS_FILE." );
		} elseif ( $ns < 0 ) {
			throw new MWException( "Invalid or virtual namespace $ns given." );
		}

		switch ( $ns ) {
			case NS_FILE:
				$page = new WikiFilePage( $title );
				break;
			case NS_CATEGORY:
				$page = new WikiCategoryPage( $title );
				break;
			default:
				$page = new WikiPage( $title );
		}

		return $page;
	}

	/**
	 * Constructor from a page id
	 *
	 * @param int $id Article ID to load
	 * @param string|int $from One of the following values:
	 *        - "fromdb" or WikiPage::READ_NORMAL to select from a slave database
	 *        - "fromdbmaster" or WikiPage::READ_LATEST to select from the master database
	 *
	 * @return WikiPage|null
	 */
	public static function newFromID( $id, $from = 'fromdb' ) {
		// page id's are never 0 or negative, see bug 61166
		if ( $id < 1 ) {
			return null;
		}

		$from = self::convertSelectType( $from );
		$db = wfGetDB( $from === self::READ_LATEST ? DB_MASTER : DB_SLAVE );
		$row = $db->selectRow( 'page', self::selectFields(), array( 'page_id' => $id ), __METHOD__ );
		if ( !$row ) {
			return null;
		}
		return self::newFromRow( $row, $from );
	}

	/**
	 * Constructor from a database row
	 *
	 * @since 1.20
	 * @param object $row Database row containing at least fields returned by selectFields().
	 * @param string|int $from Source of $data:
	 *        - "fromdb" or WikiPage::READ_NORMAL: from a slave DB
	 *        - "fromdbmaster" or WikiPage::READ_LATEST: from the master DB
	 *        - "forupdate" or WikiPage::READ_LOCKING: from the master DB using SELECT FOR UPDATE
	 * @return WikiPage
	 */
	public static function newFromRow( $row, $from = 'fromdb' ) {
		$page = self::factory( Title::newFromRow( $row ) );
		$page->loadFromRow( $row, $from );
		return $page;
	}

	/**
	 * Convert 'fromdb', 'fromdbmaster' and 'forupdate' to READ_* constants.
	 *
	 * @param object|string|int $type
	 * @return mixed
	 */
	private static function convertSelectType( $type ) {
		switch ( $type ) {
		case 'fromdb':
			return self::READ_NORMAL;
		case 'fromdbmaster':
			return self::READ_LATEST;
		case 'forupdate':
			return self::READ_LOCKING;
		default:
			// It may already be an integer or whatever else
			return $type;
		}
	}

	/**
	 * Returns overrides for action handlers.
	 * Classes listed here will be used instead of the default one when
	 * (and only when) $wgActions[$action] === true. This allows subclasses
	 * to override the default behavior.
	 *
	 * @todo Move this UI stuff somewhere else
	 *
	 * @return array
	 */
	public function getActionOverrides() {
		$content_handler = $this->getContentHandler();
		return $content_handler->getActionOverrides();
	}

	/**
	 * Returns the ContentHandler instance to be used to deal with the content of this WikiPage.
	 *
	 * Shorthand for ContentHandler::getForModelID( $this->getContentModel() );
	 *
	 * @return ContentHandler
	 *
	 * @since 1.21
	 */
	public function getContentHandler() {
		return ContentHandler::getForModelID( $this->getContentModel() );
	}

	/**
	 * Get the title object of the article
	 * @return Title Title object of this page
	 */
	public function getTitle() {
		return $this->mTitle;
	}

	/**
	 * Clear the object
	 * @return void
	 */
	public function clear() {
		$this->mDataLoaded = false;
		$this->mDataLoadedFrom = self::READ_NONE;

		$this->clearCacheFields();
	}

	/**
	 * Clear the object cache fields
	 * @return void
	 */
	protected function clearCacheFields() {
		$this->mId = null;
		$this->mRedirectTarget = null; // Title object if set
		$this->mLastRevision = null; // Latest revision
		$this->mTouched = '19700101000000';
		$this->mLinksUpdated = '19700101000000';
		$this->mTimestamp = '';
		$this->mIsRedirect = false;
		$this->mLatest = false;
		// Bug 57026: do not clear mPreparedEdit since prepareTextForEdit() already checks
		// the requested rev ID and content against the cached one for equality. For most
		// content types, the output should not change during the lifetime of this cache.
		// Clearing it can cause extra parses on edit for no reason.
	}

	/**
	 * Clear the mPreparedEdit cache field, as may be needed by mutable content types
	 * @return void
	 * @since 1.23
	 */
	public function clearPreparedEdit() {
		$this->mPreparedEdit = false;
	}

	/**
	 * Return the list of revision fields that should be selected to create
	 * a new page.
	 *
	 * @return array
	 */
	public static function selectFields() {
		global $wgContentHandlerUseDB, $wgPageLanguageUseDB;

		$fields = array(
			'page_id',
			'page_namespace',
			'page_title',
			'page_restrictions',
			'page_is_redirect',
			'page_is_new',
			'page_random',
			'page_touched',
			'page_links_updated',
			'page_latest',
			'page_len',
		);

		if ( $wgContentHandlerUseDB ) {
			$fields[] = 'page_content_model';
		}

		if ( $wgPageLanguageUseDB ) {
			$fields[] = 'page_lang';
		}

		return $fields;
	}

	/**
	 * Fetch a page record with the given conditions
	 * @param DatabaseBase $dbr
	 * @param array $conditions
	 * @param array $options
	 * @return object|bool Database result resource, or false on failure
	 */
	protected function pageData( $dbr, $conditions, $options = array() ) {
		$fields = self::selectFields();

		Hooks::run( 'ArticlePageDataBefore', array( &$this, &$fields ) );

		$row = $dbr->selectRow( 'page', $fields, $conditions, __METHOD__, $options );

		Hooks::run( 'ArticlePageDataAfter', array( &$this, &$row ) );

		return $row;
	}

	/**
	 * Fetch a page record matching the Title object's namespace and title
	 * using a sanitized title string
	 *
	 * @param DatabaseBase $dbr
	 * @param Title $title
	 * @param array $options
	 * @return object|bool Database result resource, or false on failure
	 */
	public function pageDataFromTitle( $dbr, $title, $options = array() ) {
		return $this->pageData( $dbr, array(
			'page_namespace' => $title->getNamespace(),
			'page_title' => $title->getDBkey() ), $options );
	}

	/**
	 * Fetch a page record matching the requested ID
	 *
	 * @param DatabaseBase $dbr
	 * @param int $id
	 * @param array $options
	 * @return object|bool Database result resource, or false on failure
	 */
	public function pageDataFromId( $dbr, $id, $options = array() ) {
		return $this->pageData( $dbr, array( 'page_id' => $id ), $options );
	}

	/**
	 * Load the object from a given source by title
	 *
	 * @param object|string|int $from One of the following:
	 *   - A DB query result object.
	 *   - "fromdb" or WikiPage::READ_NORMAL to get from a slave DB.
	 *   - "fromdbmaster" or WikiPage::READ_LATEST to get from the master DB.
	 *   - "forupdate"  or WikiPage::READ_LOCKING to get from the master DB
	 *     using SELECT FOR UPDATE.
	 *
	 * @return void
	 */
	public function loadPageData( $from = 'fromdb' ) {
		$from = self::convertSelectType( $from );
		if ( is_int( $from ) && $from <= $this->mDataLoadedFrom ) {
			// We already have the data from the correct location, no need to load it twice.
			return;
		}

		if ( is_int( $from ) ) {
			list( $index, $opts ) = DBAccessObjectUtils::getDBOptions( $from );
			$data = $this->pageDataFromTitle( wfGetDB( $index ), $this->mTitle, $opts );

			if ( !$data
				&& $index == DB_SLAVE
				&& wfGetLB()->getServerCount() > 1
				&& wfGetLB()->hasOrMadeRecentMasterChanges()
			) {
				$from = self::READ_LATEST;
				list( $index, $opts ) = DBAccessObjectUtils::getDBOptions( $from );
				$data = $this->pageDataFromTitle( wfGetDB( $index ), $this->mTitle, $opts );
			}
		} else {
			// No idea from where the caller got this data, assume slave database.
			$data = $from;
			$from = self::READ_NORMAL;
		}

		$this->loadFromRow( $data, $from );
	}

	/**
	 * Load the object from a database row
	 *
	 * @since 1.20
	 * @param object $data Database row containing at least fields returned by selectFields()
	 * @param string|int $from One of the following:
	 *        - "fromdb" or WikiPage::READ_NORMAL if the data comes from a slave DB
	 *        - "fromdbmaster" or WikiPage::READ_LATEST if the data comes from the master DB
	 *        - "forupdate"  or WikiPage::READ_LOCKING if the data comes from
	 *          the master DB using SELECT FOR UPDATE
	 */
	public function loadFromRow( $data, $from ) {
		$lc = LinkCache::singleton();
		$lc->clearLink( $this->mTitle );

		if ( $data ) {
			$lc->addGoodLinkObjFromRow( $this->mTitle, $data );

			$this->mTitle->loadFromRow( $data );

			// Old-fashioned restrictions
			$this->mTitle->loadRestrictions( $data->page_restrictions );

			$this->mId = intval( $data->page_id );
			$this->mTouched = wfTimestamp( TS_MW, $data->page_touched );
			$this->mLinksUpdated = wfTimestampOrNull( TS_MW, $data->page_links_updated );
			$this->mIsRedirect = intval( $data->page_is_redirect );
			$this->mLatest = intval( $data->page_latest );
			// Bug 37225: $latest may no longer match the cached latest Revision object.
			// Double-check the ID of any cached latest Revision object for consistency.
			if ( $this->mLastRevision && $this->mLastRevision->getId() != $this->mLatest ) {
				$this->mLastRevision = null;
				$this->mTimestamp = '';
			}
		} else {
			$lc->addBadLinkObj( $this->mTitle );

			$this->mTitle->loadFromRow( false );

			$this->clearCacheFields();

			$this->mId = 0;
		}

		$this->mDataLoaded = true;
		$this->mDataLoadedFrom = self::convertSelectType( $from );
	}

	/**
	 * @return int Page ID
	 */
	public function getId() {
		if ( !$this->mDataLoaded ) {
			$this->loadPageData();
		}
		return $this->mId;
	}

	/**
	 * @return bool Whether or not the page exists in the database
	 */
	public function exists() {
		if ( !$this->mDataLoaded ) {
			$this->loadPageData();
		}
		return $this->mId > 0;
	}

	/**
	 * Check if this page is something we're going to be showing
	 * some sort of sensible content for. If we return false, page
	 * views (plain action=view) will return an HTTP 404 response,
	 * so spiders and robots can know they're following a bad link.
	 *
	 * @return bool
	 */
	public function hasViewableContent() {
		return $this->exists() || $this->mTitle->isAlwaysKnown();
	}

	/**
	 * Tests if the article content represents a redirect
	 *
	 * @return bool
	 */
	public function isRedirect() {
		$content = $this->getContent();
		if ( !$content ) {
			return false;
		}

		return $content->isRedirect();
	}

	/**
	 * Returns the page's content model id (see the CONTENT_MODEL_XXX constants).
	 *
	 * Will use the revisions actual content model if the page exists,
	 * and the page's default if the page doesn't exist yet.
	 *
	 * @return string
	 *
	 * @since 1.21
	 */
	public function getContentModel() {
		if ( $this->exists() ) {
			// look at the revision's actual content model
			$rev = $this->getRevision();

			if ( $rev !== null ) {
				return $rev->getContentModel();
			} else {
				$title = $this->mTitle->getPrefixedDBkey();
				wfWarn( "Page $title exists but has no (visible) revisions!" );
			}
		}

		// use the default model for this page
		return $this->mTitle->getContentModel();
	}

	/**
	 * Loads page_touched and returns a value indicating if it should be used
	 * @return bool True if not a redirect
	 */
	public function checkTouched() {
		if ( !$this->mDataLoaded ) {
			$this->loadPageData();
		}
		return !$this->mIsRedirect;
	}

	/**
	 * Get the page_touched field
	 * @return string Containing GMT timestamp
	 */
	public function getTouched() {
		if ( !$this->mDataLoaded ) {
			$this->loadPageData();
		}
		return $this->mTouched;
	}

	/**
	 * Get the page_links_updated field
	 * @return string|null Containing GMT timestamp
	 */
	public function getLinksTimestamp() {
		if ( !$this->mDataLoaded ) {
			$this->loadPageData();
		}
		return $this->mLinksUpdated;
	}

	/**
	 * Get the page_latest field
	 * @return int The rev_id of current revision
	 */
	public function getLatest() {
		if ( !$this->mDataLoaded ) {
			$this->loadPageData();
		}
		return (int)$this->mLatest;
	}

	/**
	 * Get the Revision object of the oldest revision
	 * @return Revision|null
	 */
	public function getOldestRevision() {

		// Try using the slave database first, then try the master
		$continue = 2;
		$db = wfGetDB( DB_SLAVE );
		$revSelectFields = Revision::selectFields();

		$row = null;
		while ( $continue ) {
			$row = $db->selectRow(
				array( 'page', 'revision' ),
				$revSelectFields,
				array(
					'page_namespace' => $this->mTitle->getNamespace(),
					'page_title' => $this->mTitle->getDBkey(),
					'rev_page = page_id'
				),
				__METHOD__,
				array(
					'ORDER BY' => 'rev_timestamp ASC'
				)
			);

			if ( $row ) {
				$continue = 0;
			} else {
				$db = wfGetDB( DB_MASTER );
				$continue--;
			}
		}

		return $row ? Revision::newFromRow( $row ) : null;
	}

	/**
	 * Loads everything except the text
	 * This isn't necessary for all uses, so it's only done if needed.
	 */
	protected function loadLastEdit() {
		if ( $this->mLastRevision !== null ) {
			return; // already loaded
		}

		$latest = $this->getLatest();
		if ( !$latest ) {
			return; // page doesn't exist or is missing page_latest info
		}

		if ( $this->mDataLoadedFrom == self::READ_LOCKING ) {
			// Bug 37225: if session S1 loads the page row FOR UPDATE, the result always
			// includes the latest changes committed. This is true even within REPEATABLE-READ
			// transactions, where S1 normally only sees changes committed before the first S1
			// SELECT. Thus we need S1 to also gets the revision row FOR UPDATE; otherwise, it
			// may not find it since a page row UPDATE and revision row INSERT by S2 may have
			// happened after the first S1 SELECT.
			// http://dev.mysql.com/doc/refman/5.0/en/set-transaction.html#isolevel_repeatable-read.
			$flags = Revision::READ_LOCKING;
		} elseif ( $this->mDataLoadedFrom == self::READ_LATEST ) {
			// Bug T93976: if page_latest was loaded from the master, fetch the
			// revision from there as well, as it may not exist yet on a slave DB.
			// Also, this keeps the queries in the same REPEATABLE-READ snapshot.
			$flags = Revision::READ_LATEST;
		} else {
			$flags = 0;
		}
		$revision = Revision::newFromPageId( $this->getId(), $latest, $flags );
		if ( $revision ) { // sanity
			$this->setLastEdit( $revision );
		}
	}

	/**
	 * Set the latest revision
	 * @param Revision $revision
	 */
	protected function setLastEdit( Revision $revision ) {
		$this->mLastRevision = $revision;
		$this->mTimestamp = $revision->getTimestamp();
	}

	/**
	 * Get the latest revision
	 * @return Revision|null
	 */
	public function getRevision() {
		$this->loadLastEdit();
		if ( $this->mLastRevision ) {
			return $this->mLastRevision;
		}
		return null;
	}

	/**
	 * Get the content of the current revision. No side-effects...
	 *
	 * @param int $audience One of:
	 *   Revision::FOR_PUBLIC       to be displayed to all users
	 *   Revision::FOR_THIS_USER    to be displayed to $wgUser
	 *   Revision::RAW              get the text regardless of permissions
	 * @param User $user User object to check for, only if FOR_THIS_USER is passed
	 *   to the $audience parameter
	 * @return Content|null The content of the current revision
	 *
	 * @since 1.21
	 */
	public function getContent( $audience = Revision::FOR_PUBLIC, User $user = null ) {
		$this->loadLastEdit();
		if ( $this->mLastRevision ) {
			return $this->mLastRevision->getContent( $audience, $user );
		}
		return null;
	}

	/**
	 * Get the text of the current revision. No side-effects...
	 *
	 * @param int $audience One of:
	 *   Revision::FOR_PUBLIC       to be displayed to all users
	 *   Revision::FOR_THIS_USER    to be displayed to the given user
	 *   Revision::RAW              get the text regardless of permissions
	 * @param User $user User object to check for, only if FOR_THIS_USER is passed
	 *   to the $audience parameter
	 * @return string|bool The text of the current revision
	 * @deprecated since 1.21, getContent() should be used instead.
	 */
	public function getText( $audience = Revision::FOR_PUBLIC, User $user = null ) {
		ContentHandler::deprecated( __METHOD__, '1.21' );

		$this->loadLastEdit();
		if ( $this->mLastRevision ) {
			return $this->mLastRevision->getText( $audience, $user );
		}
		return false;
	}

	/**
	 * Get the text of the current revision. No side-effects...
	 *
	 * @return string|bool The text of the current revision. False on failure
	 * @deprecated since 1.21, getContent() should be used instead.
	 */
	public function getRawText() {
		ContentHandler::deprecated( __METHOD__, '1.21' );

		return $this->getText( Revision::RAW );
	}

	/**
	 * @return string MW timestamp of last article revision
	 */
	public function getTimestamp() {
		// Check if the field has been filled by WikiPage::setTimestamp()
		if ( !$this->mTimestamp ) {
			$this->loadLastEdit();
		}

		return wfTimestamp( TS_MW, $this->mTimestamp );
	}

	/**
	 * Set the page timestamp (use only to avoid DB queries)
	 * @param string $ts MW timestamp of last article revision
	 * @return void
	 */
	public function setTimestamp( $ts ) {
		$this->mTimestamp = wfTimestamp( TS_MW, $ts );
	}

	/**
	 * @param int $audience One of:
	 *   Revision::FOR_PUBLIC       to be displayed to all users
	 *   Revision::FOR_THIS_USER    to be displayed to the given user
	 *   Revision::RAW              get the text regardless of permissions
	 * @param User $user User object to check for, only if FOR_THIS_USER is passed
	 *   to the $audience parameter
	 * @return int User ID for the user that made the last article revision
	 */
	public function getUser( $audience = Revision::FOR_PUBLIC, User $user = null ) {
		$this->loadLastEdit();
		if ( $this->mLastRevision ) {
			return $this->mLastRevision->getUser( $audience, $user );
		} else {
			return -1;
		}
	}

	/**
	 * Get the User object of the user who created the page
	 * @param int $audience One of:
	 *   Revision::FOR_PUBLIC       to be displayed to all users
	 *   Revision::FOR_THIS_USER    to be displayed to the given user
	 *   Revision::RAW              get the text regardless of permissions
	 * @param User $user User object to check for, only if FOR_THIS_USER is passed
	 *   to the $audience parameter
	 * @return User|null
	 */
	public function getCreator( $audience = Revision::FOR_PUBLIC, User $user = null ) {
		$revision = $this->getOldestRevision();
		if ( $revision ) {
			$userName = $revision->getUserText( $audience, $user );
			return User::newFromName( $userName, false );
		} else {
			return null;
		}
	}

	/**
	 * @param int $audience One of:
	 *   Revision::FOR_PUBLIC       to be displayed to all users
	 *   Revision::FOR_THIS_USER    to be displayed to the given user
	 *   Revision::RAW              get the text regardless of permissions
	 * @param User $user User object to check for, only if FOR_THIS_USER is passed
	 *   to the $audience parameter
	 * @return string Username of the user that made the last article revision
	 */
	public function getUserText( $audience = Revision::FOR_PUBLIC, User $user = null ) {
		$this->loadLastEdit();
		if ( $this->mLastRevision ) {
			return $this->mLastRevision->getUserText( $audience, $user );
		} else {
			return '';
		}
	}

	/**
	 * @param int $audience One of:
	 *   Revision::FOR_PUBLIC       to be displayed to all users
	 *   Revision::FOR_THIS_USER    to be displayed to the given user
	 *   Revision::RAW              get the text regardless of permissions
	 * @param User $user User object to check for, only if FOR_THIS_USER is passed
	 *   to the $audience parameter
	 * @return string Comment stored for the last article revision
	 */
	public function getComment( $audience = Revision::FOR_PUBLIC, User $user = null ) {
		$this->loadLastEdit();
		if ( $this->mLastRevision ) {
			return $this->mLastRevision->getComment( $audience, $user );
		} else {
			return '';
		}
	}

	/**
	 * Returns true if last revision was marked as "minor edit"
	 *
	 * @return bool Minor edit indicator for the last article revision.
	 */
	public function getMinorEdit() {
		$this->loadLastEdit();
		if ( $this->mLastRevision ) {
			return $this->mLastRevision->isMinor();
		} else {
			return false;
		}
	}

	/**
	 * Determine whether a page would be suitable for being counted as an
	 * article in the site_stats table based on the title & its content
	 *
	 * @param object|bool $editInfo (false): object returned by prepareTextForEdit(),
	 *   if false, the current database state will be used
	 * @return bool
	 */
	public function isCountable( $editInfo = false ) {
		global $wgArticleCountMethod;

		if ( !$this->mTitle->isContentPage() ) {
			return false;
		}

		if ( $editInfo ) {
			$content = $editInfo->pstContent;
		} else {
			$content = $this->getContent();
		}

		if ( !$content || $content->isRedirect() ) {
			return false;
		}

		$hasLinks = null;

		if ( $wgArticleCountMethod === 'link' ) {
			// nasty special case to avoid re-parsing to detect links

			if ( $editInfo ) {
				// ParserOutput::getLinks() is a 2D array of page links, so
				// to be really correct we would need to recurse in the array
				// but the main array should only have items in it if there are
				// links.
				$hasLinks = (bool)count( $editInfo->output->getLinks() );
			} else {
				$hasLinks = (bool)wfGetDB( DB_SLAVE )->selectField( 'pagelinks', 1,
					array( 'pl_from' => $this->getId() ), __METHOD__ );
			}
		}

		return $content->isCountable( $hasLinks );
	}

	/**
	 * If this page is a redirect, get its target
	 *
	 * The target will be fetched from the redirect table if possible.
	 * If this page doesn't have an entry there, call insertRedirect()
	 * @return Title|null Title object, or null if this page is not a redirect
	 */
	public function getRedirectTarget() {
		if ( !$this->mTitle->isRedirect() ) {
			return null;
		}

		if ( $this->mRedirectTarget !== null ) {
			return $this->mRedirectTarget;
		}

		// Query the redirect table
		$dbr = wfGetDB( DB_SLAVE );
		$row = $dbr->selectRow( 'redirect',
			array( 'rd_namespace', 'rd_title', 'rd_fragment', 'rd_interwiki' ),
			array( 'rd_from' => $this->getId() ),
			__METHOD__
		);

		// rd_fragment and rd_interwiki were added later, populate them if empty
		if ( $row && !is_null( $row->rd_fragment ) && !is_null( $row->rd_interwiki ) ) {
			$this->mRedirectTarget = Title::makeTitle(
				$row->rd_namespace, $row->rd_title,
				$row->rd_fragment, $row->rd_interwiki );
			return $this->mRedirectTarget;
		}

		// This page doesn't have an entry in the redirect table
		$this->mRedirectTarget = $this->insertRedirect();
		return $this->mRedirectTarget;
	}

	/**
	 * Insert an entry for this page into the redirect table.
	 *
	 * Don't call this function directly unless you know what you're doing.
	 * @return Title|null Title object or null if not a redirect
	 */
	public function insertRedirect() {
		// recurse through to only get the final target
		$content = $this->getContent();
		$retval = $content ? $content->getUltimateRedirectTarget() : null;
		if ( !$retval ) {
			return null;
		}
		$this->insertRedirectEntry( $retval );
		return $retval;
	}

	/**
	 * Insert or update the redirect table entry for this page to indicate
	 * it redirects to $rt .
	 * @param Title $rt Redirect target
	 */
	public function insertRedirectEntry( $rt ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->replace( 'redirect', array( 'rd_from' ),
			array(
				'rd_from' => $this->getId(),
				'rd_namespace' => $rt->getNamespace(),
				'rd_title' => $rt->getDBkey(),
				'rd_fragment' => $rt->getFragment(),
				'rd_interwiki' => $rt->getInterwiki(),
			),
			__METHOD__
		);
	}

	/**
	 * Get the Title object or URL this page redirects to
	 *
	 * @return bool|Title|string False, Title of in-wiki target, or string with URL
	 */
	public function followRedirect() {
		return $this->getRedirectURL( $this->getRedirectTarget() );
	}

	/**
	 * Get the Title object or URL to use for a redirect. We use Title
	 * objects for same-wiki, non-special redirects and URLs for everything
	 * else.
	 * @param Title $rt Redirect target
	 * @return bool|Title|string False, Title object of local target, or string with URL
	 */
	public function getRedirectURL( $rt ) {
		if ( !$rt ) {
			return false;
		}

		if ( $rt->isExternal() ) {
			if ( $rt->isLocal() ) {
				// Offsite wikis need an HTTP redirect.
				//
				// This can be hard to reverse and may produce loops,
				// so they may be disabled in the site configuration.
				$source = $this->mTitle->getFullURL( 'redirect=no' );
				return $rt->getFullURL( array( 'rdfrom' => $source ) );
			} else {
				// External pages without "local" bit set are not valid
				// redirect targets
				return false;
			}
		}

		if ( $rt->isSpecialPage() ) {
			// Gotta handle redirects to special pages differently:
			// Fill the HTTP response "Location" header and ignore
			// the rest of the page we're on.
			//
			// Some pages are not valid targets
			if ( $rt->isValidRedirectTarget() ) {
				return $rt->getFullURL();
			} else {
				return false;
			}
		}

		return $rt;
	}

	/**
	 * Get a list of users who have edited this article, not including the user who made
	 * the most recent revision, which you can get from $article->getUser() if you want it
	 * @return UserArrayFromResult
	 */
	public function getContributors() {
		// @todo FIXME: This is expensive; cache this info somewhere.

		$dbr = wfGetDB( DB_SLAVE );

		if ( $dbr->implicitGroupby() ) {
			$realNameField = 'user_real_name';
		} else {
			$realNameField = 'MIN(user_real_name) AS user_real_name';
		}

		$tables = array( 'revision', 'user' );

		$fields = array(
			'user_id' => 'rev_user',
			'user_name' => 'rev_user_text',
			$realNameField,
			'timestamp' => 'MAX(rev_timestamp)',
		);

		$conds = array( 'rev_page' => $this->getId() );

		// The user who made the top revision gets credited as "this page was last edited by
		// John, based on contributions by Tom, Dick and Harry", so don't include them twice.
		$user = $this->getUser();
		if ( $user ) {
			$conds[] = "rev_user != $user";
		} else {
			$conds[] = "rev_user_text != {$dbr->addQuotes( $this->getUserText() )}";
		}

		$conds[] = "{$dbr->bitAnd( 'rev_deleted', Revision::DELETED_USER )} = 0"; // username hidden?

		$jconds = array(
			'user' => array( 'LEFT JOIN', 'rev_user = user_id' ),
		);

		$options = array(
			'GROUP BY' => array( 'rev_user', 'rev_user_text' ),
			'ORDER BY' => 'timestamp DESC',
		);

		$res = $dbr->select( $tables, $fields, $conds, __METHOD__, $options, $jconds );
		return new UserArrayFromResult( $res );
	}

	/**
	 * Get the last N authors
	 * @param int $num Number of revisions to get
	 * @param int|string $revLatest The latest rev_id, selected from the master (optional)
	 * @return array Array of authors, duplicates not removed
	 */
	public function getLastNAuthors( $num, $revLatest = 0 ) {
		// First try the slave
		// If that doesn't have the latest revision, try the master
		$continue = 2;
		$db = wfGetDB( DB_SLAVE );

		do {
			$res = $db->select( array( 'page', 'revision' ),
				array( 'rev_id', 'rev_user_text' ),
				array(
					'page_namespace' => $this->mTitle->getNamespace(),
					'page_title' => $this->mTitle->getDBkey(),
					'rev_page = page_id'
				), __METHOD__,
				array(
					'ORDER BY' => 'rev_timestamp DESC',
					'LIMIT' => $num
				)
			);

			if ( !$res ) {
				return array();
			}

			$row = $db->fetchObject( $res );

			if ( $continue == 2 && $revLatest && $row->rev_id != $revLatest ) {
				$db = wfGetDB( DB_MASTER );
				$continue--;
			} else {
				$continue = 0;
			}
		} while ( $continue );

		$authors = array( $row->rev_user_text );

		foreach ( $res as $row ) {
			$authors[] = $row->rev_user_text;
		}

		return $authors;
	}

	/**
	 * Should the parser cache be used?
	 *
	 * @param ParserOptions $parserOptions ParserOptions to check
	 * @param int $oldId
	 * @return bool
	 */
	public function shouldCheckParserCache( ParserOptions $parserOptions, $oldId ) {
		return $parserOptions->getStubThreshold() == 0
			&& $this->exists()
			&& ( $oldId === null || $oldId === 0 || $oldId === $this->getLatest() )
			&& $this->getContentHandler()->isParserCacheSupported();
	}

	/**
	 * Get a ParserOutput for the given ParserOptions and revision ID.
	 * The parser cache will be used if possible.
	 *
	 * @since 1.19
	 * @param ParserOptions $parserOptions ParserOptions to use for the parse operation
	 * @param null|int $oldid Revision ID to get the text from, passing null or 0 will
	 *   get the current revision (default value)
	 *
	 * @return ParserOutput|bool ParserOutput or false if the revision was not found
	 */
	public function getParserOutput( ParserOptions $parserOptions, $oldid = null ) {

		$useParserCache = $this->shouldCheckParserCache( $parserOptions, $oldid );
		wfDebug( __METHOD__ . ': using parser cache: ' . ( $useParserCache ? 'yes' : 'no' ) . "\n" );
		if ( $parserOptions->getStubThreshold() ) {
			wfIncrStats( 'pcache.miss.stub' );
		}

		if ( $useParserCache ) {
			$parserOutput = ParserCache::singleton()->get( $this, $parserOptions );
			if ( $parserOutput !== false ) {
				return $parserOutput;
			}
		}

		if ( $oldid === null || $oldid === 0 ) {
			$oldid = $this->getLatest();
		}

		$pool = new PoolWorkArticleView( $this, $parserOptions, $oldid, $useParserCache );
		$pool->execute();

		return $pool->getParserOutput();
	}

	/**
	 * Do standard deferred updates after page view (existing or missing page)
	 * @param User $user The relevant user
	 * @param int $oldid The revision id being viewed. If not given or 0, latest revision is assumed.
	 */
	public function doViewUpdates( User $user, $oldid = 0 ) {
		if ( wfReadOnly() ) {
			return;
		}

		Hooks::run( 'PageViewUpdates', array( $this, $user ) );
		// Update newtalk / watchlist notification status
		try {
			$user->clearNotification( $this->mTitle, $oldid );
		} catch ( DBError $e ) {
			// Avoid outage if the master is not reachable
			MWExceptionHandler::logException( $e );
		}
	}

	/**
	 * Perform the actions of a page purging
	 * @return bool
	 */
	public function doPurge() {
		if ( !Hooks::run( 'ArticlePurge', array( &$this ) ) ) {
			return false;
		}

		$title = $this->mTitle;
		wfGetDB( DB_MASTER )->onTransactionIdle( function() use ( $title ) {
			global $wgUseSquid;
			// Invalidate the cache in auto-commit mode
			$title->invalidateCache();
			if ( $wgUseSquid ) {
				// Send purge now that page_touched update was committed above
				$update = SquidUpdate::newSimplePurge( $title );
				$update->doUpdate();
			}
		} );

		if ( $this->mTitle->getNamespace() == NS_MEDIAWIKI ) {
			// @todo move this logic to MessageCache
			if ( $this->exists() ) {
				// NOTE: use transclusion text for messages.
				//       This is consistent with  MessageCache::getMsgFromNamespace()

				$content = $this->getContent();
				$text = $content === null ? null : $content->getWikitextForTransclusion();

				if ( $text === null ) {
					$text = false;
				}
			} else {
				$text = false;
			}

			MessageCache::singleton()->replace( $this->mTitle->getDBkey(), $text );
		}

		return true;
	}

	/**
	 * Insert a new empty page record for this article.
	 * This *must* be followed up by creating a revision
	 * and running $this->updateRevisionOn( ... );
	 * or else the record will be left in a funky state.
	 * Best if all done inside a transaction.
	 *
	 * @param DatabaseBase $dbw
	 * @return int|bool The newly created page_id key; false if the title already existed
	 */
	public function insertOn( $dbw ) {
		$page_id = $dbw->nextSequenceValue( 'page_page_id_seq' );
		$dbw->insert( 'page', array(
			'page_id'           => $page_id,
			'page_namespace'    => $this->mTitle->getNamespace(),
			'page_title'        => $this->mTitle->getDBkey(),
			'page_restrictions' => '',
			'page_is_redirect'  => 0, // Will set this shortly...
			'page_is_new'       => 1,
			'page_random'       => wfRandom(),
			'page_touched'      => $dbw->timestamp(),
			'page_latest'       => 0, // Fill this in shortly...
			'page_len'          => 0, // Fill this in shortly...
		), __METHOD__, 'IGNORE' );

		$affected = $dbw->affectedRows();

		if ( $affected ) {
			$newid = $dbw->insertId();
			$this->mId = $newid;
			$this->mTitle->resetArticleID( $newid );

			return $newid;
		} else {
			return false;
		}
	}

	/**
	 * Update the page record to point to a newly saved revision.
	 *
	 * @param DatabaseBase $dbw
	 * @param Revision $revision For ID number, and text used to set
	 *   length and redirect status fields
	 * @param int $lastRevision If given, will not overwrite the page field
	 *   when different from the currently set value.
	 *   Giving 0 indicates the new page flag should be set on.
	 * @param bool $lastRevIsRedirect If given, will optimize adding and
	 *   removing rows in redirect table.
	 * @return bool True on success, false on failure
	 */
	public function updateRevisionOn( $dbw, $revision, $lastRevision = null,
		$lastRevIsRedirect = null
	) {
		global $wgContentHandlerUseDB;

		// Assertion to try to catch T92046
		if ( (int)$revision->getId() === 0 ) {
			throw new InvalidArgumentException(
				__METHOD__ . ': Revision has ID ' . var_export( $revision->getId(), 1 )
			);
		}

		$content = $revision->getContent();
		$len = $content ? $content->getSize() : 0;
		$rt = $content ? $content->getUltimateRedirectTarget() : null;

		$conditions = array( 'page_id' => $this->getId() );

		if ( !is_null( $lastRevision ) ) {
			// An extra check against threads stepping on each other
			$conditions['page_latest'] = $lastRevision;
		}

		$row = array( /* SET */
			'page_latest'      => $revision->getId(),
			'page_touched'     => $dbw->timestamp( $revision->getTimestamp() ),
			'page_is_new'      => ( $lastRevision === 0 ) ? 1 : 0,
			'page_is_redirect' => $rt !== null ? 1 : 0,
			'page_len'         => $len,
		);

		if ( $wgContentHandlerUseDB ) {
			$row['page_content_model'] = $revision->getContentModel();
		}

		$dbw->update( 'page',
			$row,
			$conditions,
			__METHOD__ );

		$result = $dbw->affectedRows() > 0;
		if ( $result ) {
			$this->updateRedirectOn( $dbw, $rt, $lastRevIsRedirect );
			$this->setLastEdit( $revision );
			$this->mLatest = $revision->getId();
			$this->mIsRedirect = (bool)$rt;
			// Update the LinkCache.
			LinkCache::singleton()->addGoodLinkObj( $this->getId(), $this->mTitle, $len, $this->mIsRedirect,
													$this->mLatest, $revision->getContentModel() );
		}

		return $result;
	}

	/**
	 * Add row to the redirect table if this is a redirect, remove otherwise.
	 *
	 * @param DatabaseBase $dbw
	 * @param Title $redirectTitle Title object pointing to the redirect target,
	 *   or NULL if this is not a redirect
	 * @param null|bool $lastRevIsRedirect If given, will optimize adding and
	 *   removing rows in redirect table.
	 * @return bool True on success, false on failure
	 * @private
	 */
	public function updateRedirectOn( $dbw, $redirectTitle, $lastRevIsRedirect = null ) {
		// Always update redirects (target link might have changed)
		// Update/Insert if we don't know if the last revision was a redirect or not
		// Delete if changing from redirect to non-redirect
		$isRedirect = !is_null( $redirectTitle );

		if ( !$isRedirect && $lastRevIsRedirect === false ) {
			return true;
		}

		if ( $isRedirect ) {
			$this->insertRedirectEntry( $redirectTitle );
		} else {
			// This is not a redirect, remove row from redirect table
			$where = array( 'rd_from' => $this->getId() );
			$dbw->delete( 'redirect', $where, __METHOD__ );
		}

		if ( $this->getTitle()->getNamespace() == NS_FILE ) {
			RepoGroup::singleton()->getLocalRepo()->invalidateImageRedirect( $this->getTitle() );
		}

		return ( $dbw->affectedRows() != 0 );
	}

	/**
	 * If the given revision is newer than the currently set page_latest,
	 * update the page record. Otherwise, do nothing.
	 *
	 * @deprecated since 1.24, use updateRevisionOn instead
	 *
	 * @param DatabaseBase $dbw
	 * @param Revision $revision
	 * @return bool
	 */
	public function updateIfNewerOn( $dbw, $revision ) {

		$row = $dbw->selectRow(
			array( 'revision', 'page' ),
			array( 'rev_id', 'rev_timestamp', 'page_is_redirect' ),
			array(
				'page_id' => $this->getId(),
				'page_latest=rev_id' ),
			__METHOD__ );

		if ( $row ) {
			if ( wfTimestamp( TS_MW, $row->rev_timestamp ) >= $revision->getTimestamp() ) {
				return false;
			}
			$prev = $row->rev_id;
			$lastRevIsRedirect = (bool)$row->page_is_redirect;
		} else {
			// No or missing previous revision; mark the page as new
			$prev = 0;
			$lastRevIsRedirect = null;
		}

		$ret = $this->updateRevisionOn( $dbw, $revision, $prev, $lastRevIsRedirect );

		return $ret;
	}

	/**
	 * Get the content that needs to be saved in order to undo all revisions
	 * between $undo and $undoafter. Revisions must belong to the same page,
	 * must exist and must not be deleted
	 * @param Revision $undo
	 * @param Revision $undoafter Must be an earlier revision than $undo
	 * @return mixed String on success, false on failure
	 * @since 1.21
	 * Before we had the Content object, this was done in getUndoText
	 */
	public function getUndoContent( Revision $undo, Revision $undoafter = null ) {
		$handler = $undo->getContentHandler();
		return $handler->getUndoContent( $this->getRevision(), $undo, $undoafter );
	}

	/**
	 * Get the text that needs to be saved in order to undo all revisions
	 * between $undo and $undoafter. Revisions must belong to the same page,
	 * must exist and must not be deleted
	 * @param Revision $undo
	 * @param Revision $undoafter Must be an earlier revision than $undo
	 * @return string|bool String on success, false on failure
	 * @deprecated since 1.21: use ContentHandler::getUndoContent() instead.
	 */
	public function getUndoText( Revision $undo, Revision $undoafter = null ) {
		ContentHandler::deprecated( __METHOD__, '1.21' );

		$this->loadLastEdit();

		if ( $this->mLastRevision ) {
			if ( is_null( $undoafter ) ) {
				$undoafter = $undo->getPrevious();
			}

			$handler = $this->getContentHandler();
			$undone = $handler->getUndoContent( $this->mLastRevision, $undo, $undoafter );

			if ( !$undone ) {
				return false;
			} else {
				return ContentHandler::getContentText( $undone );
			}
		}

		return false;
	}

	/**
	 * @param string|number|null|bool $sectionId Section identifier as a number or string
	 * (e.g. 0, 1 or 'T-1'), null/false or an empty string for the whole page
	 * or 'new' for a new section.
	 * @param string $text New text of the section.
	 * @param string $sectionTitle New section's subject, only if $section is "new".
	 * @param string $edittime Revision timestamp or null to use the current revision.
	 *
	 * @throws MWException
	 * @return string New complete article text, or null if error.
	 *
	 * @deprecated since 1.21, use replaceSectionAtRev() instead
	 */
	public function replaceSection( $sectionId, $text, $sectionTitle = '',
		$edittime = null
	) {
		ContentHandler::deprecated( __METHOD__, '1.21' );

		//NOTE: keep condition in sync with condition in replaceSectionContent!
		if ( strval( $sectionId ) === '' ) {
			// Whole-page edit; let the whole text through
			return $text;
		}

		if ( !$this->supportsSections() ) {
			throw new MWException( "sections not supported for content model " .
				$this->getContentHandler()->getModelID() );
		}

		// could even make section title, but that's not required.
		$sectionContent = ContentHandler::makeContent( $text, $this->getTitle() );

		$newContent = $this->replaceSectionContent( $sectionId, $sectionContent, $sectionTitle,
			$edittime );

		return ContentHandler::getContentText( $newContent );
	}

	/**
	 * Returns true if this page's content model supports sections.
	 *
	 * @return bool
	 *
	 * @todo The skin should check this and not offer section functionality if
	 *   sections are not supported.
	 * @todo The EditPage should check this and not offer section functionality
	 *   if sections are not supported.
	 */
	public function supportsSections() {
		return $this->getContentHandler()->supportsSections();
	}

	/**
	 * @param string|number|null|bool $sectionId Section identifier as a number or string
	 * (e.g. 0, 1 or 'T-1'), null/false or an empty string for the whole page
	 * or 'new' for a new section.
	 * @param Content $sectionContent New content of the section.
	 * @param string $sectionTitle New section's subject, only if $section is "new".
	 * @param string $edittime Revision timestamp or null to use the current revision.
	 *
	 * @throws MWException
	 * @return Content New complete article content, or null if error.
	 *
	 * @since 1.21
	 * @deprecated since 1.24, use replaceSectionAtRev instead
	 */
	public function replaceSectionContent( $sectionId, Content $sectionContent, $sectionTitle = '',
		$edittime = null ) {

		$baseRevId = null;
		if ( $edittime && $sectionId !== 'new' ) {
			$dbr = wfGetDB( DB_SLAVE );
			$rev = Revision::loadFromTimestamp( $dbr, $this->mTitle, $edittime );
			// Try the master if this thread may have just added it.
			// This could be abstracted into a Revision method, but we don't want
			// to encourage loading of revisions by timestamp.
			if ( !$rev
				&& wfGetLB()->getServerCount() > 1
				&& wfGetLB()->hasOrMadeRecentMasterChanges()
			) {
				$dbw = wfGetDB( DB_MASTER );
				$rev = Revision::loadFromTimestamp( $dbw, $this->mTitle, $edittime );
			}
			if ( $rev ) {
				$baseRevId = $rev->getId();
			}
		}

		return $this->replaceSectionAtRev( $sectionId, $sectionContent, $sectionTitle, $baseRevId );
	}

	/**
	 * @param string|number|null|bool $sectionId Section identifier as a number or string
	 * (e.g. 0, 1 or 'T-1'), null/false or an empty string for the whole page
	 * or 'new' for a new section.
	 * @param Content $sectionContent New content of the section.
	 * @param string $sectionTitle New section's subject, only if $section is "new".
	 * @param int|null $baseRevId
	 *
	 * @throws MWException
	 * @return Content New complete article content, or null if error.
	 *
	 * @since 1.24
	 */
	public function replaceSectionAtRev( $sectionId, Content $sectionContent,
		$sectionTitle = '', $baseRevId = null
	) {

		if ( strval( $sectionId ) === '' ) {
			// Whole-page edit; let the whole text through
			$newContent = $sectionContent;
		} else {
			if ( !$this->supportsSections() ) {
				throw new MWException( "sections not supported for content model " .
					$this->getContentHandler()->getModelID() );
			}

			// Bug 30711: always use current version when adding a new section
			if ( is_null( $baseRevId ) || $sectionId === 'new' ) {
				$oldContent = $this->getContent();
			} else {
				$rev = Revision::newFromId( $baseRevId );
				if ( !$rev ) {
					wfDebug( __METHOD__ . " asked for bogus section (page: " .
						$this->getId() . "; section: $sectionId)\n" );
					return null;
				}

				$oldContent = $rev->getContent();
			}

			if ( !$oldContent ) {
				wfDebug( __METHOD__ . ": no page text\n" );
				return null;
			}

			$newContent = $oldContent->replaceSection( $sectionId, $sectionContent, $sectionTitle );
		}

		return $newContent;
	}

	/**
	 * Check flags and add EDIT_NEW or EDIT_UPDATE to them as needed.
	 * @param int $flags
	 * @return int Updated $flags
	 */
	public function checkFlags( $flags ) {
		if ( !( $flags & EDIT_NEW ) && !( $flags & EDIT_UPDATE ) ) {
			if ( $this->exists() ) {
				$flags |= EDIT_UPDATE;
			} else {
				$flags |= EDIT_NEW;
			}
		}

		return $flags;
	}

	/**
	 * Change an existing article or create a new article. Updates RC and all necessary caches,
	 * optionally via the deferred update array.
	 *
	 * @param string $text New text
	 * @param string $summary Edit summary
	 * @param int $flags Bitfield:
	 *      EDIT_NEW
	 *          Article is known or assumed to be non-existent, create a new one
	 *      EDIT_UPDATE
	 *          Article is known or assumed to be pre-existing, update it
	 *      EDIT_MINOR
	 *          Mark this edit minor, if the user is allowed to do so
	 *      EDIT_SUPPRESS_RC
	 *          Do not log the change in recentchanges
	 *      EDIT_FORCE_BOT
	 *          Mark the edit a "bot" edit regardless of user rights
	 *      EDIT_DEFER_UPDATES
	 *          Defer some of the updates until the end of index.php
	 *      EDIT_AUTOSUMMARY
	 *          Fill in blank summaries with generated text where possible
	 *
	 * If neither EDIT_NEW nor EDIT_UPDATE is specified, the status of the
	 * article will be detected. If EDIT_UPDATE is specified and the article
	 * doesn't exist, the function will return an edit-gone-missing error. If
	 * EDIT_NEW is specified and the article does exist, an edit-already-exists
	 * error will be returned. These two conditions are also possible with
	 * auto-detection due to MediaWiki's performance-optimised locking strategy.
	 *
	 * @param bool|int $baseRevId The revision ID this edit was based off, if any.
	 *   This is not the parent revision ID, rather the revision ID for older
	 *   content used as the source for a rollback, for example.
	 * @param User $user The user doing the edit
	 *
	 * @throws MWException
	 * @return Status Possible errors:
	 *   edit-hook-aborted: The ArticleSave hook aborted the edit but didn't
	 *     set the fatal flag of $status
	 *   edit-gone-missing: In update mode, but the article didn't exist.
	 *   edit-conflict: In update mode, the article changed unexpectedly.
	 *   edit-no-change: Warning that the text was the same as before.
	 *   edit-already-exists: In creation mode, but the article already exists.
	 *
	 * Extensions may define additional errors.
	 *
	 * $return->value will contain an associative array with members as follows:
	 *     new: Boolean indicating if the function attempted to create a new article.
	 *     revision: The revision object for the inserted revision, or null.
	 *
	 * Compatibility note: this function previously returned a boolean value
	 * indicating success/failure
	 *
	 * @deprecated since 1.21: use doEditContent() instead.
	 */
	public function doEdit( $text, $summary, $flags = 0, $baseRevId = false, $user = null ) {
		ContentHandler::deprecated( __METHOD__, '1.21' );

		$content = ContentHandler::makeContent( $text, $this->getTitle() );

		return $this->doEditContent( $content, $summary, $flags, $baseRevId, $user );
	}

	/**
	 * Change an existing article or create a new article. Updates RC and all necessary caches,
	 * optionally via the deferred update array.
	 *
	 * @param Content $content New content
	 * @param string $summary Edit summary
	 * @param int $flags Bitfield:
	 *      EDIT_NEW
	 *          Article is known or assumed to be non-existent, create a new one
	 *      EDIT_UPDATE
	 *          Article is known or assumed to be pre-existing, update it
	 *      EDIT_MINOR
	 *          Mark this edit minor, if the user is allowed to do so
	 *      EDIT_SUPPRESS_RC
	 *          Do not log the change in recentchanges
	 *      EDIT_FORCE_BOT
	 *          Mark the edit a "bot" edit regardless of user rights
	 *      EDIT_DEFER_UPDATES
	 *          Defer some of the updates until the end of index.php
	 *      EDIT_AUTOSUMMARY
	 *          Fill in blank summaries with generated text where possible
	 *
	 * If neither EDIT_NEW nor EDIT_UPDATE is specified, the status of the
	 * article will be detected. If EDIT_UPDATE is specified and the article
	 * doesn't exist, the function will return an edit-gone-missing error. If
	 * EDIT_NEW is specified and the article does exist, an edit-already-exists
	 * error will be returned. These two conditions are also possible with
	 * auto-detection due to MediaWiki's performance-optimised locking strategy.
	 *
	 * @param bool|int $baseRevId The revision ID this edit was based off, if any.
	 *   This is not the parent revision ID, rather the revision ID for older
	 *   content used as the source for a rollback, for example.
	 * @param User $user The user doing the edit
	 * @param string $serialFormat Format for storing the content in the
	 *   database.
	 *
	 * @throws MWException
	 * @return Status Possible errors:
	 *     edit-hook-aborted: The ArticleSave hook aborted the edit but didn't
	 *       set the fatal flag of $status.
	 *     edit-gone-missing: In update mode, but the article didn't exist.
	 *     edit-conflict: In update mode, the article changed unexpectedly.
	 *     edit-no-change: Warning that the text was the same as before.
	 *     edit-already-exists: In creation mode, but the article already exists.
	 *
	 *  Extensions may define additional errors.
	 *
	 *  $return->value will contain an associative array with members as follows:
	 *     new: Boolean indicating if the function attempted to create a new article.
	 *     revision: The revision object for the inserted revision, or null.
	 *
	 * @since 1.21
	 * @throws MWException
	 */
	public function doEditContent( Content $content, $summary, $flags = 0, $baseRevId = false,
		User $user = null, $serialFormat = null
	) {
		global $wgUser, $wgUseAutomaticEditSummaries, $wgUseRCPatrol, $wgUseNPPatrol;

		// Low-level sanity check
		if ( $this->mTitle->getText() === '' ) {
			throw new MWException( 'Something is trying to edit an article with an empty title' );
		}

		if ( !$content->getContentHandler()->canBeUsedOn( $this->getTitle() ) ) {
			return Status::newFatal( 'content-not-allowed-here',
				ContentHandler::getLocalizedName( $content->getModel() ),
				$this->getTitle()->getPrefixedText() );
		}

		$user = is_null( $user ) ? $wgUser : $user;
		$status = Status::newGood( array() );

		// Load the data from the master database if needed.
		// The caller may already loaded it from the master or even loaded it using
		// SELECT FOR UPDATE, so do not override that using clear().
		$this->loadPageData( 'fromdbmaster' );

		$flags = $this->checkFlags( $flags );

		// handle hook
		$hook_args = array( &$this, &$user, &$content, &$summary,
							$flags & EDIT_MINOR, null, null, &$flags, &$status );

		if ( !Hooks::run( 'PageContentSave', $hook_args )
			|| !ContentHandler::runLegacyHooks( 'ArticleSave', $hook_args ) ) {

			wfDebug( __METHOD__ . ": ArticleSave or ArticleSaveContent hook aborted save!\n" );

			if ( $status->isOK() ) {
				$status->fatal( 'edit-hook-aborted' );
			}

			return $status;
		}

		// Silently ignore EDIT_MINOR if not allowed
		$isminor = ( $flags & EDIT_MINOR ) && $user->isAllowed( 'minoredit' );
		$bot = $flags & EDIT_FORCE_BOT;

		$old_content = $this->getContent( Revision::RAW ); // current revision's content

		$oldsize = $old_content ? $old_content->getSize() : 0;
		$oldid = $this->getLatest();
		$oldIsRedirect = $this->isRedirect();
		$oldcountable = $this->isCountable();

		$handler = $content->getContentHandler();

		// Provide autosummaries if one is not provided and autosummaries are enabled.
		if ( $wgUseAutomaticEditSummaries && $flags & EDIT_AUTOSUMMARY && $summary == '' ) {
			if ( !$old_content ) {
				$old_content = null;
			}
			$summary = $handler->getAutosummary( $old_content, $content, $flags );
		}

		$editInfo = $this->prepareContentForEdit( $content, null, $user, $serialFormat );
		$serialized = $editInfo->pst;

		/**
		 * @var Content $content
		 */
		$content = $editInfo->pstContent;
		$newsize = $content->getSize();

		$dbw = wfGetDB( DB_MASTER );
		$now = wfTimestampNow();
		$this->mTimestamp = $now;

		if ( $flags & EDIT_UPDATE ) {
			// Update article, but only if changed.
			$status->value['new'] = false;

			if ( !$oldid ) {
				// Article gone missing
				wfDebug( __METHOD__ . ": EDIT_UPDATE specified but article doesn't exist\n" );
				$status->fatal( 'edit-gone-missing' );

				return $status;
			} elseif ( !$old_content ) {
				// Sanity check for bug 37225
				throw new MWException( "Could not find text for current revision {$oldid}." );
			}

			$revision = new Revision( array(
				'page'       => $this->getId(),
				'title'      => $this->getTitle(), // for determining the default content model
				'comment'    => $summary,
				'minor_edit' => $isminor,
				'text'       => $serialized,
				'len'        => $newsize,
				'parent_id'  => $oldid,
				'user'       => $user->getId(),
				'user_text'  => $user->getName(),
				'timestamp'  => $now,
				'content_model' => $content->getModel(),
				'content_format' => $serialFormat,
			) ); // XXX: pass content object?!

			$changed = !$content->equals( $old_content );

			if ( $changed ) {
				$dbw->begin( __METHOD__ );

				$prepStatus = $content->prepareSave( $this, $flags, $oldid, $user );
				$status->merge( $prepStatus );

				if ( !$status->isOK() ) {
					$dbw->rollback( __METHOD__ );

					return $status;
				}
				$revisionId = $revision->insertOn( $dbw );

				// Update page
				//
				// We check for conflicts by comparing $oldid with the current latest revision ID.
				$ok = $this->updateRevisionOn( $dbw, $revision, $oldid, $oldIsRedirect );

				if ( !$ok ) {
					// Belated edit conflict! Run away!!
					$status->fatal( 'edit-conflict' );

					$dbw->rollback( __METHOD__ );

					return $status;
				}

				Hooks::run( 'NewRevisionFromEditComplete', array( $this, $revision, $baseRevId, $user ) );

				// Update recentchanges
				if ( !( $flags & EDIT_SUPPRESS_RC ) ) {
					// Mark as patrolled if the user can do so
					$patrolled = $wgUseRCPatrol && !count(
						$this->mTitle->getUserPermissionsErrors( 'autopatrol', $user ) );
					// Add RC row to the DB
					RecentChange::notifyEdit(
						$now, $this->mTitle, $isminor, $user, $summary,
						$oldid, $this->getTimestamp(), $bot, '', $oldsize, $newsize,
						$revisionId, $patrolled
					);
				}

				$user->incEditCount();

				$dbw->commit( __METHOD__ );
			} else {
				// Bug 32948: revision ID must be set to page {{REVISIONID}} and
				// related variables correctly
				$revision->setId( $this->getLatest() );
			}

			// Update links tables, site stats, etc.
			$this->doEditUpdates(
				$revision,
				$user,
				array(
					'changed' => $changed,
					'oldcountable' => $oldcountable
				)
			);

			if ( !$changed ) {
				$status->warning( 'edit-no-change' );
				$revision = null;
				// Update page_touched, this is usually implicit in the page update
				// Other cache updates are done in onArticleEdit()
				$this->mTitle->invalidateCache( $now );
			}
		} else {
			// Create new article
			$status->value['new'] = true;

			$dbw->begin( __METHOD__ );

			$prepStatus = $content->prepareSave( $this, $flags, $oldid, $user );
			$status->merge( $prepStatus );

			if ( !$status->isOK() ) {
				$dbw->rollback( __METHOD__ );

				return $status;
			}

			$status->merge( $prepStatus );

			// Add the page record; stake our claim on this title!
			// This will return false if the article already exists
			$newid = $this->insertOn( $dbw );

			if ( $newid === false ) {
				$dbw->rollback( __METHOD__ );
				$status->fatal( 'edit-already-exists' );

				return $status;
			}

			// Save the revision text...
			$revision = new Revision( array(
				'page'       => $newid,
				'title'      => $this->getTitle(), // for determining the default content model
				'comment'    => $summary,
				'minor_edit' => $isminor,
				'text'       => $serialized,
				'len'        => $newsize,
				'user'       => $user->getId(),
				'user_text'  => $user->getName(),
				'timestamp'  => $now,
				'content_model' => $content->getModel(),
				'content_format' => $serialFormat,
			) );
			$revisionId = $revision->insertOn( $dbw );

			// Bug 37225: use accessor to get the text as Revision may trim it
			$content = $revision->getContent(); // sanity; get normalized version

			if ( $content ) {
				$newsize = $content->getSize();
			}

			// Update the page record with revision data
			$this->updateRevisionOn( $dbw, $revision, 0 );

			Hooks::run( 'NewRevisionFromEditComplete', array( $this, $revision, false, $user ) );

			// Update recentchanges
			if ( !( $flags & EDIT_SUPPRESS_RC ) ) {
				// Mark as patrolled if the user can do so
				$patrolled = ( $wgUseRCPatrol || $wgUseNPPatrol ) && !count(
					$this->mTitle->getUserPermissionsErrors( 'autopatrol', $user ) );
				// Add RC row to the DB
				RecentChange::notifyNew(
					$now, $this->mTitle, $isminor, $user, $summary, $bot,
					'', $newsize, $revisionId, $patrolled
				);
			}

			$user->incEditCount();

			$dbw->commit( __METHOD__ );

			// Update links, etc.
			$this->doEditUpdates( $revision, $user, array( 'created' => true ) );

			$hook_args = array( &$this, &$user, $content, $summary,
								$flags & EDIT_MINOR, null, null, &$flags, $revision );

			ContentHandler::runLegacyHooks( 'ArticleInsertComplete', $hook_args );
			Hooks::run( 'PageContentInsertComplete', $hook_args );
		}

		// Do updates right now unless deferral was requested
		if ( !( $flags & EDIT_DEFER_UPDATES ) ) {
			DeferredUpdates::doUpdates();
		}

		// Return the new revision (or null) to the caller
		$status->value['revision'] = $revision;

		$hook_args = array( &$this, &$user, $content, $summary,
			$flags & EDIT_MINOR, null, null, &$flags, $revision, &$status, $baseRevId );

		ContentHandler::runLegacyHooks( 'ArticleSaveComplete', $hook_args );
		Hooks::run( 'PageContentSaveComplete', $hook_args );

		// Promote user to any groups they meet the criteria for
		DeferredUpdates::addCallableUpdate( function () use ( $user ) {
			$user->addAutopromoteOnceGroups( 'onEdit' );
			$user->addAutopromoteOnceGroups( 'onView' ); // b/c
		} );

		return $status;
	}

	/**
	 * Get parser options suitable for rendering the primary article wikitext
	 *
	 * @see ContentHandler::makeParserOptions
	 *
	 * @param IContextSource|User|string $context One of the following:
	 *        - IContextSource: Use the User and the Language of the provided
	 *          context
	 *        - User: Use the provided User object and $wgLang for the language,
	 *          so use an IContextSource object if possible.
	 *        - 'canonical': Canonical options (anonymous user with default
	 *          preferences and content language).
	 * @return ParserOptions
	 */
	public function makeParserOptions( $context ) {
		$options = $this->getContentHandler()->makeParserOptions( $context );

		if ( $this->getTitle()->isConversionTable() ) {
			// @todo ConversionTable should become a separate content model, so
			// we don't need special cases like this one.
			$options->disableContentConversion();
		}

		return $options;
	}

	/**
	 * Prepare text which is about to be saved.
	 * Returns a stdClass with source, pst and output members
	 *
	 * @deprecated since 1.21: use prepareContentForEdit instead.
	 * @return object
	 */
	public function prepareTextForEdit( $text, $revid = null, User $user = null ) {
		ContentHandler::deprecated( __METHOD__, '1.21' );
		$content = ContentHandler::makeContent( $text, $this->getTitle() );
		return $this->prepareContentForEdit( $content, $revid, $user );
	}

	/**
	 * Prepare content which is about to be saved.
	 * Returns a stdClass with source, pst and output members
	 *
	 * @param Content $content
	 * @param Revision|int|null $revision Revision object. For backwards compatibility, a
	 *        revision ID is also accepted, but this is deprecated.
	 * @param User|null $user
	 * @param string|null $serialFormat
	 * @param bool $useCache Check shared prepared edit cache
	 *
	 * @return object
	 *
	 * @since 1.21
	 */
	public function prepareContentForEdit(
		Content $content, $revision = null, User $user = null, $serialFormat = null, $useCache = true
	) {
		global $wgContLang, $wgUser, $wgAjaxEditStash;

		if ( is_object( $revision ) ) {
			$revid = $revision->getId();
		} else {
			$revid = $revision;
			// This code path is deprecated, and nothing is known to
			// use it, so performance here shouldn't be a worry.
			if ( $revid !== null ) {
				$revision = Revision::newFromId( $revid, Revision::READ_LATEST );
			} else {
				$revision = null;
			}
		}

		$user = is_null( $user ) ? $wgUser : $user;
		//XXX: check $user->getId() here???

		// Use a sane default for $serialFormat, see bug 57026
		if ( $serialFormat === null ) {
			$serialFormat = $content->getContentHandler()->getDefaultFormat();
		}

		if ( $this->mPreparedEdit
			&& $this->mPreparedEdit->newContent
			&& $this->mPreparedEdit->newContent->equals( $content )
			&& $this->mPreparedEdit->revid == $revid
			&& $this->mPreparedEdit->format == $serialFormat
			// XXX: also check $user here?
		) {
			// Already prepared
			return $this->mPreparedEdit;
		}

		// The edit may have already been prepared via api.php?action=stashedit
		$cachedEdit = $useCache && $wgAjaxEditStash && !$user->isAllowed( 'bot' )
			? ApiStashEdit::checkCache( $this->getTitle(), $content, $user )
			: false;

		$popts = ParserOptions::newFromUserAndLang( $user, $wgContLang );
		Hooks::run( 'ArticlePrepareTextForEdit', array( $this, $popts ) );

		$edit = (object)array();
		if ( $cachedEdit ) {
			$edit->timestamp = $cachedEdit->timestamp;
		} else {
			$edit->timestamp = wfTimestampNow();
		}
		// @note: $cachedEdit is not used if the rev ID was referenced in the text
		$edit->revid = $revid;

		if ( $cachedEdit ) {
			$edit->pstContent = $cachedEdit->pstContent;
		} else {
			$edit->pstContent = $content
				? $content->preSaveTransform( $this->mTitle, $user, $popts )
				: null;
		}

		$edit->format = $serialFormat;
		$edit->popts = $this->makeParserOptions( 'canonical' );
		if ( $cachedEdit ) {
			$edit->output = $cachedEdit->output;
		} else {
			if ( $revision ) {
				// We get here if vary-revision is set. This means that this page references
				// itself (such as via self-transclusion). In this case, we need to make sure
				// that any such self-references refer to the newly-saved revision, and not
				// to the previous one, which could otherwise happen due to slave lag.
				$oldCallback = $edit->popts->setCurrentRevisionCallback(
					function ( $title, $parser = false ) use ( $revision, &$oldCallback ) {
						if ( $title->equals( $revision->getTitle() ) ) {
							return $revision;
						} else {
							return call_user_func(
								$oldCallback,
								$title,
								$parser
							);
						}
					}
				);
			}
			$edit->output = $edit->pstContent
				? $edit->pstContent->getParserOutput( $this->mTitle, $revid, $edit->popts )
				: null;
		}

		$edit->newContent = $content;
		$edit->oldContent = $this->getContent( Revision::RAW );

		// NOTE: B/C for hooks! don't use these fields!
		$edit->newText = $edit->newContent ? ContentHandler::getContentText( $edit->newContent ) : '';
		$edit->oldText = $edit->oldContent ? ContentHandler::getContentText( $edit->oldContent ) : '';
		$edit->pst = $edit->pstContent ? $edit->pstContent->serialize( $serialFormat ) : '';

		$this->mPreparedEdit = $edit;
		return $edit;
	}

	/**
	 * Do standard deferred updates after page edit.
	 * Update links tables, site stats, search index and message cache.
	 * Purges pages that include this page if the text was changed here.
	 * Every 100th edit, prune the recent changes table.
	 *
	 * @param Revision $revision
	 * @param User $user User object that did the revision
	 * @param array $options Array of options, following indexes are used:
	 * - changed: boolean, whether the revision changed the content (default true)
	 * - created: boolean, whether the revision created the page (default false)
	 * - moved: boolean, whether the page was moved (default false)
	 * - oldcountable: boolean, null, or string 'no-change' (default null):
	 *   - boolean: whether the page was counted as an article before that
	 *     revision, only used in changed is true and created is false
	 *   - null: if created is false, don't update the article count; if created
	 *     is true, do update the article count
	 *   - 'no-change': don't update the article count, ever
	 */
	public function doEditUpdates( Revision $revision, User $user, array $options = array() ) {
		$options += array(
			'changed' => true,
			'created' => false,
			'moved' => false,
			'oldcountable' => null
		);
		$content = $revision->getContent();

		// Parse the text
		// Be careful not to do pre-save transform twice: $text is usually
		// already pre-save transformed once.
		if ( !$this->mPreparedEdit || $this->mPreparedEdit->output->getFlag( 'vary-revision' ) ) {
			wfDebug( __METHOD__ . ": No prepared edit or vary-revision is set...\n" );
			$editInfo = $this->prepareContentForEdit( $content, $revision, $user );
		} else {
			wfDebug( __METHOD__ . ": No vary-revision, using prepared edit...\n" );
			$editInfo = $this->mPreparedEdit;
		}

		// Save it to the parser cache.
		// Make sure the cache time matches page_touched to avoid double parsing.
		ParserCache::singleton()->save(
			$editInfo->output, $this, $editInfo->popts,
			$revision->getTimestamp(), $editInfo->revid
		);

		// Update the links tables and other secondary data
		if ( $content ) {
			$recursive = $options['changed']; // bug 50785
			$updates = $content->getSecondaryDataUpdates(
				$this->getTitle(), null, $recursive, $editInfo->output );
			foreach ( $updates as $update ) {
				DeferredUpdates::addUpdate( $update );
			}
		}

		Hooks::run( 'ArticleEditUpdates', array( &$this, &$editInfo, $options['changed'] ) );

		if ( Hooks::run( 'ArticleEditUpdatesDeleteFromRecentchanges', array( &$this ) ) ) {
			// Flush old entries from the `recentchanges` table
			if ( mt_rand( 0, 9 ) == 0 ) {
				JobQueueGroup::singleton()->lazyPush( RecentChangesUpdateJob::newPurgeJob() );
			}
		}

		if ( !$this->exists() ) {
			return;
		}

		$id = $this->getId();
		$title = $this->mTitle->getPrefixedDBkey();
		$shortTitle = $this->mTitle->getDBkey();

		if ( $options['oldcountable'] === 'no-change' ||
			( !$options['changed'] && !$options['moved'] )
		) {
			$good = 0;
		} elseif ( $options['created'] ) {
			$good = (int)$this->isCountable( $editInfo );
		} elseif ( $options['oldcountable'] !== null ) {
			$good = (int)$this->isCountable( $editInfo ) - (int)$options['oldcountable'];
		} else {
			$good = 0;
		}
		$edits = $options['changed'] ? 1 : 0;
		$total = $options['created'] ? 1 : 0;

		DeferredUpdates::addUpdate( new SiteStatsUpdate( 0, $edits, $good, $total ) );
		DeferredUpdates::addUpdate( new SearchUpdate( $id, $title, $content ) );

		// If this is another user's talk page, update newtalk.
		// Don't do this if $options['changed'] = false (null-edits) nor if
		// it's a minor edit and the user doesn't want notifications for those.
		if ( $options['changed']
			&& $this->mTitle->getNamespace() == NS_USER_TALK
			&& $shortTitle != $user->getTitleKey()
			&& !( $revision->isMinor() && $user->isAllowed( 'nominornewtalk' ) )
		) {
			$recipient = User::newFromName( $shortTitle, false );
			if ( !$recipient ) {
				wfDebug( __METHOD__ . ": invalid username\n" );
			} else {
				// Allow extensions to prevent user notification when a new message is added to their talk page
				if ( Hooks::run( 'ArticleEditUpdateNewTalk', array( &$this, $recipient ) ) ) {
					if ( User::isIP( $shortTitle ) ) {
						// An anonymous user
						$recipient->setNewtalk( true, $revision );
					} elseif ( $recipient->isLoggedIn() ) {
						$recipient->setNewtalk( true, $revision );
					} else {
						wfDebug( __METHOD__ . ": don't need to notify a nonexistent user\n" );
					}
				}
			}
		}

		if ( $this->mTitle->getNamespace() == NS_MEDIAWIKI ) {
			// XXX: could skip pseudo-messages like js/css here, based on content model.
			$msgtext = $content ? $content->getWikitextForTransclusion() : null;
			if ( $msgtext === false || $msgtext === null ) {
				$msgtext = '';
			}

			MessageCache::singleton()->replace( $shortTitle, $msgtext );
		}

		if ( $options['created'] ) {
			self::onArticleCreate( $this->mTitle );
		} elseif ( $options['changed'] ) { // bug 50785
			self::onArticleEdit( $this->mTitle, $revision );
		}
	}

	/**
	 * Edit an article without doing all that other stuff
	 * The article must already exist; link tables etc
	 * are not updated, caches are not flushed.
	 *
	 * @param string $text Text submitted
	 * @param User $user The relevant user
	 * @param string $comment Comment submitted
	 * @param bool $minor Whereas it's a minor modification
	 *
	 * @deprecated since 1.21, use doEditContent() instead.
	 */
	public function doQuickEdit( $text, User $user, $comment = '', $minor = 0 ) {
		ContentHandler::deprecated( __METHOD__, "1.21" );

		$content = ContentHandler::makeContent( $text, $this->getTitle() );
		$this->doQuickEditContent( $content, $user, $comment, $minor );
	}

	/**
	 * Edit an article without doing all that other stuff
	 * The article must already exist; link tables etc
	 * are not updated, caches are not flushed.
	 *
	 * @param Content $content Content submitted
	 * @param User $user The relevant user
	 * @param string $comment Comment submitted
	 * @param bool $minor Whereas it's a minor modification
	 * @param string $serialFormat Format for storing the content in the database
	 */
	public function doQuickEditContent( Content $content, User $user, $comment = '', $minor = false,
		$serialFormat = null
	) {

		$serialized = $content->serialize( $serialFormat );

		$dbw = wfGetDB( DB_MASTER );
		$revision = new Revision( array(
			'title'      => $this->getTitle(), // for determining the default content model
			'page'       => $this->getId(),
			'user_text'  => $user->getName(),
			'user'       => $user->getId(),
			'text'       => $serialized,
			'length'     => $content->getSize(),
			'comment'    => $comment,
			'minor_edit' => $minor ? 1 : 0,
		) ); // XXX: set the content object?
		$revision->insertOn( $dbw );
		$this->updateRevisionOn( $dbw, $revision );

		Hooks::run( 'NewRevisionFromEditComplete', array( $this, $revision, false, $user ) );

	}

	/**
	 * Update the article's restriction field, and leave a log entry.
	 * This works for protection both existing and non-existing pages.
	 *
	 * @param array $limit Set of restriction keys
	 * @param array $expiry Per restriction type expiration
	 * @param int &$cascade Set to false if cascading protection isn't allowed.
	 * @param string $reason
	 * @param User $user The user updating the restrictions
	 * @return Status
	 */
	public function doUpdateRestrictions( array $limit, array $expiry,
		&$cascade, $reason, User $user
	) {
		global $wgCascadingRestrictionLevels, $wgContLang;

		if ( wfReadOnly() ) {
			return Status::newFatal( 'readonlytext', wfReadOnlyReason() );
		}

		$this->loadPageData( 'fromdbmaster' );
		$restrictionTypes = $this->mTitle->getRestrictionTypes();
		$id = $this->getId();

		if ( !$cascade ) {
			$cascade = false;
		}

		// Take this opportunity to purge out expired restrictions
		Title::purgeExpiredRestrictions();

		// @todo FIXME: Same limitations as described in ProtectionForm.php (line 37);
		// we expect a single selection, but the schema allows otherwise.
		$isProtected = false;
		$protect = false;
		$changed = false;

		$dbw = wfGetDB( DB_MASTER );

		foreach ( $restrictionTypes as $action ) {
			if ( !isset( $expiry[$action] ) || $expiry[$action] === $dbw->getInfinity() ) {
				$expiry[$action] = 'infinity';
			}
			if ( !isset( $limit[$action] ) ) {
				$limit[$action] = '';
			} elseif ( $limit[$action] != '' ) {
				$protect = true;
			}

			// Get current restrictions on $action
			$current = implode( '', $this->mTitle->getRestrictions( $action ) );
			if ( $current != '' ) {
				$isProtected = true;
			}

			if ( $limit[$action] != $current ) {
				$changed = true;
			} elseif ( $limit[$action] != '' ) {
				// Only check expiry change if the action is actually being
				// protected, since expiry does nothing on an not-protected
				// action.
				if ( $this->mTitle->getRestrictionExpiry( $action ) != $expiry[$action] ) {
					$changed = true;
				}
			}
		}

		if ( !$changed && $protect && $this->mTitle->areRestrictionsCascading() != $cascade ) {
			$changed = true;
		}

		// If nothing has changed, do nothing
		if ( !$changed ) {
			return Status::newGood();
		}

		if ( !$protect ) { // No protection at all means unprotection
			$revCommentMsg = 'unprotectedarticle';
			$logAction = 'unprotect';
		} elseif ( $isProtected ) {
			$revCommentMsg = 'modifiedarticleprotection';
			$logAction = 'modify';
		} else {
			$revCommentMsg = 'protectedarticle';
			$logAction = 'protect';
		}

		// Truncate for whole multibyte characters
		$reason = $wgContLang->truncate( $reason, 255 );

		$logRelationsValues = array();
		$logRelationsField = null;

		if ( $id ) { // Protection of existing page
			if ( !Hooks::run( 'ArticleProtect', array( &$this, &$user, $limit, $reason ) ) ) {
				return Status::newGood();
			}

			// Only certain restrictions can cascade...
			$editrestriction = isset( $limit['edit'] )
				? array( $limit['edit'] )
				: $this->mTitle->getRestrictions( 'edit' );
			foreach ( array_keys( $editrestriction, 'sysop' ) as $key ) {
				$editrestriction[$key] = 'editprotected'; // backwards compatibility
			}
			foreach ( array_keys( $editrestriction, 'autoconfirmed' ) as $key ) {
				$editrestriction[$key] = 'editsemiprotected'; // backwards compatibility
			}

			$cascadingRestrictionLevels = $wgCascadingRestrictionLevels;
			foreach ( array_keys( $cascadingRestrictionLevels, 'sysop' ) as $key ) {
				$cascadingRestrictionLevels[$key] = 'editprotected'; // backwards compatibility
			}
			foreach ( array_keys( $cascadingRestrictionLevels, 'autoconfirmed' ) as $key ) {
				$cascadingRestrictionLevels[$key] = 'editsemiprotected'; // backwards compatibility
			}

			// The schema allows multiple restrictions
			if ( !array_intersect( $editrestriction, $cascadingRestrictionLevels ) ) {
				$cascade = false;
			}

			// insert null revision to identify the page protection change as edit summary
			$latest = $this->getLatest();
			$nullRevision = $this->insertProtectNullRevision(
				$revCommentMsg,
				$limit,
				$expiry,
				$cascade,
				$reason,
				$user
			);

			if ( $nullRevision === null ) {
				return Status::newFatal( 'no-null-revision', $this->mTitle->getPrefixedText() );
			}

			$logRelationsField = 'pr_id';

			// Update restrictions table
			foreach ( $limit as $action => $restrictions ) {
				$dbw->delete(
					'page_restrictions',
					array(
						'pr_page' => $id,
						'pr_type' => $action
					),
					__METHOD__
				);
				if ( $restrictions != '' ) {
					$dbw->insert(
						'page_restrictions',
						array(
							'pr_id' => $dbw->nextSequenceValue( 'page_restrictions_pr_id_seq' ),
							'pr_page' => $id,
							'pr_type' => $action,
							'pr_level' => $restrictions,
							'pr_cascade' => ( $cascade && $action == 'edit' ) ? 1 : 0,
							'pr_expiry' => $dbw->encodeExpiry( $expiry[$action] )
						),
						__METHOD__
					);
					$logRelationsValues[] = $dbw->insertId();
				}
			}

			// Clear out legacy restriction fields
			$dbw->update(
				'page',
				array( 'page_restrictions' => '' ),
				array( 'page_id' => $id ),
				__METHOD__
			);

			Hooks::run( 'NewRevisionFromEditComplete', array( $this, $nullRevision, $latest, $user ) );
			Hooks::run( 'ArticleProtectComplete', array( &$this, &$user, $limit, $reason ) );
		} else { // Protection of non-existing page (also known as "title protection")
			// Cascade protection is meaningless in this case
			$cascade = false;

			if ( $limit['create'] != '' ) {
				$dbw->replace( 'protected_titles',
					array( array( 'pt_namespace', 'pt_title' ) ),
					array(
						'pt_namespace' => $this->mTitle->getNamespace(),
						'pt_title' => $this->mTitle->getDBkey(),
						'pt_create_perm' => $limit['create'],
						'pt_timestamp' => $dbw->timestamp(),
						'pt_expiry' => $dbw->encodeExpiry( $expiry['create'] ),
						'pt_user' => $user->getId(),
						'pt_reason' => $reason,
					), __METHOD__
				);
			} else {
				$dbw->delete( 'protected_titles',
					array(
						'pt_namespace' => $this->mTitle->getNamespace(),
						'pt_title' => $this->mTitle->getDBkey()
					), __METHOD__
				);
			}
		}

		$this->mTitle->flushRestrictions();
		InfoAction::invalidateCache( $this->mTitle );

		if ( $logAction == 'unprotect' ) {
			$params = array();
		} else {
			$protectDescriptionLog = $this->protectDescriptionLog( $limit, $expiry );
			$params = array( $protectDescriptionLog, $cascade ? 'cascade' : '' );
		}

		// Update the protection log
		$log = new LogPage( 'protect' );
		$logId = $log->addEntry( $logAction, $this->mTitle, $reason, $params, $user );
		if ( $logRelationsField !== null && count( $logRelationsValues ) ) {
			$log->addRelations( $logRelationsField, $logRelationsValues, $logId );
		}

		return Status::newGood();
	}

	/**
	 * Insert a new null revision for this page.
	 *
	 * @param string $revCommentMsg Comment message key for the revision
	 * @param array $limit Set of restriction keys
	 * @param array $expiry Per restriction type expiration
	 * @param int $cascade Set to false if cascading protection isn't allowed.
	 * @param string $reason
	 * @param User|null $user
	 * @return Revision|null Null on error
	 */
	public function insertProtectNullRevision( $revCommentMsg, array $limit,
		array $expiry, $cascade, $reason, $user = null
	) {
		global $wgContLang;
		$dbw = wfGetDB( DB_MASTER );

		// Prepare a null revision to be added to the history
		$editComment = $wgContLang->ucfirst(
			wfMessage(
				$revCommentMsg,
				$this->mTitle->getPrefixedText()
			)->inContentLanguage()->text()
		);
		if ( $reason ) {
			$editComment .= wfMessage( 'colon-separator' )->inContentLanguage()->text() . $reason;
		}
		$protectDescription = $this->protectDescription( $limit, $expiry );
		if ( $protectDescription ) {
			$editComment .= wfMessage( 'word-separator' )->inContentLanguage()->text();
			$editComment .= wfMessage( 'parentheses' )->params( $protectDescription )
				->inContentLanguage()->text();
		}
		if ( $cascade ) {
			$editComment .= wfMessage( 'word-separator' )->inContentLanguage()->text();
			$editComment .= wfMessage( 'brackets' )->params(
				wfMessage( 'protect-summary-cascade' )->inContentLanguage()->text()
			)->inContentLanguage()->text();
		}

		$nullRev = Revision::newNullRevision( $dbw, $this->getId(), $editComment, true, $user );
		if ( $nullRev ) {
			$nullRev->insertOn( $dbw );

			// Update page record and touch page
			$oldLatest = $nullRev->getParentId();
			$this->updateRevisionOn( $dbw, $nullRev, $oldLatest );
		}

		return $nullRev;
	}

	/**
	 * @param string $expiry 14-char timestamp or "infinity", or false if the input was invalid
	 * @return string
	 */
	protected function formatExpiry( $expiry ) {
		global $wgContLang;

		if ( $expiry != 'infinity' ) {
			return wfMessage(
				'protect-expiring',
				$wgContLang->timeanddate( $expiry, false, false ),
				$wgContLang->date( $expiry, false, false ),
				$wgContLang->time( $expiry, false, false )
			)->inContentLanguage()->text();
		} else {
			return wfMessage( 'protect-expiry-indefinite' )
				->inContentLanguage()->text();
		}
	}

	/**
	 * Builds the description to serve as comment for the edit.
	 *
	 * @param array $limit Set of restriction keys
	 * @param array $expiry Per restriction type expiration
	 * @return string
	 */
	public function protectDescription( array $limit, array $expiry ) {
		$protectDescription = '';

		foreach ( array_filter( $limit ) as $action => $restrictions ) {
			# $action is one of $wgRestrictionTypes = array( 'create', 'edit', 'move', 'upload' ).
			# All possible message keys are listed here for easier grepping:
			# * restriction-create
			# * restriction-edit
			# * restriction-move
			# * restriction-upload
			$actionText = wfMessage( 'restriction-' . $action )->inContentLanguage()->text();
			# $restrictions is one of $wgRestrictionLevels = array( '', 'autoconfirmed', 'sysop' ),
			# with '' filtered out. All possible message keys are listed below:
			# * protect-level-autoconfirmed
			# * protect-level-sysop
			$restrictionsText = wfMessage( 'protect-level-' . $restrictions )->inContentLanguage()->text();

			$expiryText = $this->formatExpiry( $expiry[$action] );

			if ( $protectDescription !== '' ) {
				$protectDescription .= wfMessage( 'word-separator' )->inContentLanguage()->text();
			}
			$protectDescription .= wfMessage( 'protect-summary-desc' )
				->params( $actionText, $restrictionsText, $expiryText )
				->inContentLanguage()->text();
		}

		return $protectDescription;
	}

	/**
	 * Builds the description to serve as comment for the log entry.
	 *
	 * Some bots may parse IRC lines, which are generated from log entries which contain plain
	 * protect description text. Keep them in old format to avoid breaking compatibility.
	 * TODO: Fix protection log to store structured description and format it on-the-fly.
	 *
	 * @param array $limit Set of restriction keys
	 * @param array $expiry Per restriction type expiration
	 * @return string
	 */
	public function protectDescriptionLog( array $limit, array $expiry ) {
		global $wgContLang;

		$protectDescriptionLog = '';

		foreach ( array_filter( $limit ) as $action => $restrictions ) {
			$expiryText = $this->formatExpiry( $expiry[$action] );
			$protectDescriptionLog .= $wgContLang->getDirMark() . "[$action=$restrictions] ($expiryText)";
		}

		return trim( $protectDescriptionLog );
	}

	/**
	 * Take an array of page restrictions and flatten it to a string
	 * suitable for insertion into the page_restrictions field.
	 *
	 * @param string[] $limit
	 *
	 * @throws MWException
	 * @return string
	 */
	protected static function flattenRestrictions( $limit ) {
		if ( !is_array( $limit ) ) {
			throw new MWException( 'WikiPage::flattenRestrictions given non-array restriction set' );
		}

		$bits = array();
		ksort( $limit );

		foreach ( array_filter( $limit ) as $action => $restrictions ) {
			$bits[] = "$action=$restrictions";
		}

		return implode( ':', $bits );
	}

	/**
	 * Same as doDeleteArticleReal(), but returns a simple boolean. This is kept around for
	 * backwards compatibility, if you care about error reporting you should use
	 * doDeleteArticleReal() instead.
	 *
	 * Deletes the article with database consistency, writes logs, purges caches
	 *
	 * @param string $reason Delete reason for deletion log
	 * @param bool $suppress Suppress all revisions and log the deletion in
	 *        the suppression log instead of the deletion log
	 * @param int $id Article ID
	 * @param bool $commit Defaults to true, triggers transaction end
	 * @param array &$error Array of errors to append to
	 * @param User $user The deleting user
	 * @return bool True if successful
	 */
	public function doDeleteArticle(
		$reason, $suppress = false, $id = 0, $commit = true, &$error = '', User $user = null
	) {
		$status = $this->doDeleteArticleReal( $reason, $suppress, $id, $commit, $error, $user );
		return $status->isGood();
	}

	/**
	 * Back-end article deletion
	 * Deletes the article with database consistency, writes logs, purges caches
	 *
	 * @since 1.19
	 *
	 * @param string $reason Delete reason for deletion log
	 * @param bool $suppress Suppress all revisions and log the deletion in
	 *   the suppression log instead of the deletion log
	 * @param int $id Article ID
	 * @param bool $commit Defaults to true, triggers transaction end
	 * @param array &$error Array of errors to append to
	 * @param User $user The deleting user
	 * @return Status Status object; if successful, $status->value is the log_id of the
	 *   deletion log entry. If the page couldn't be deleted because it wasn't
	 *   found, $status is a non-fatal 'cannotdelete' error
	 */
	public function doDeleteArticleReal(
		$reason, $suppress = false, $id = 0, $commit = true, &$error = '', User $user = null
	) {
		global $wgUser, $wgContentHandlerUseDB;

		wfDebug( __METHOD__ . "\n" );

		$status = Status::newGood();

		if ( $this->mTitle->getDBkey() === '' ) {
			$status->error( 'cannotdelete', wfEscapeWikiText( $this->getTitle()->getPrefixedText() ) );
			return $status;
		}

		$user = is_null( $user ) ? $wgUser : $user;
		if ( !Hooks::run( 'ArticleDelete', array( &$this, &$user, &$reason, &$error, &$status ) ) ) {
			if ( $status->isOK() ) {
				// Hook aborted but didn't set a fatal status
				$status->fatal( 'delete-hook-aborted' );
			}
			return $status;
		}

		$dbw = wfGetDB( DB_MASTER );
		$dbw->begin( __METHOD__ );

		if ( $id == 0 ) {
			// T98706: lock the page from various other updates but avoid using
			// WikiPage::READ_LOCKING as that will carry over the FOR UPDATE to
			// the revisions queries (which also JOIN on user). Only lock the page
			// row and CAS check on page_latest to see if the trx snapshot matches.
			$latest = $this->lock();

			$this->loadPageData( WikiPage::READ_LATEST );
			$id = $this->getID();
			if ( $id == 0 || $this->getLatest() != $latest ) {
				// Page not there or trx snapshot is stale
				$dbw->rollback( __METHOD__ );
				$status->error( 'cannotdelete', wfEscapeWikiText( $this->getTitle()->getPrefixedText() ) );
				return $status;
			}
		}

		// we need to remember the old content so we can use it to generate all deletion updates.
		$content = $this->getContent( Revision::RAW );

		// Bitfields to further suppress the content
		if ( $suppress ) {
			$bitfield = 0;
			// This should be 15...
			$bitfield |= Revision::DELETED_TEXT;
			$bitfield |= Revision::DELETED_COMMENT;
			$bitfield |= Revision::DELETED_USER;
			$bitfield |= Revision::DELETED_RESTRICTED;
		} else {
			$bitfield = 'rev_deleted';
		}

		// For now, shunt the revision data into the archive table.
		// Text is *not* removed from the text table; bulk storage
		// is left intact to avoid breaking block-compression or
		// immutable storage schemes.
		//
		// For backwards compatibility, note that some older archive
		// table entries will have ar_text and ar_flags fields still.
		//
		// In the future, we may keep revisions and mark them with
		// the rev_deleted field, which is reserved for this purpose.

		$row = array(
			'ar_namespace'  => 'page_namespace',
			'ar_title'      => 'page_title',
			'ar_comment'    => 'rev_comment',
			'ar_user'       => 'rev_user',
			'ar_user_text'  => 'rev_user_text',
			'ar_timestamp'  => 'rev_timestamp',
			'ar_minor_edit' => 'rev_minor_edit',
			'ar_rev_id'     => 'rev_id',
			'ar_parent_id'  => 'rev_parent_id',
			'ar_text_id'    => 'rev_text_id',
			'ar_text'       => '\'\'', // Be explicit to appease
			'ar_flags'      => '\'\'', // MySQL's "strict mode"...
			'ar_len'        => 'rev_len',
			'ar_page_id'    => 'page_id',
			'ar_deleted'    => $bitfield,
			'ar_sha1'       => 'rev_sha1',
		);

		if ( $wgContentHandlerUseDB ) {
			$row['ar_content_model'] = 'rev_content_model';
			$row['ar_content_format'] = 'rev_content_format';
		}

		$dbw->insertSelect( 'archive', array( 'page', 'revision' ),
			$row,
			array(
				'page_id' => $id,
				'page_id = rev_page'
			), __METHOD__
		);

		// Now that it's safely backed up, delete it
		$dbw->delete( 'page', array( 'page_id' => $id ), __METHOD__ );
		$ok = ( $dbw->affectedRows() > 0 ); // $id could be laggy

		if ( !$ok ) {
			$dbw->rollback( __METHOD__ );
			$status->error( 'cannotdelete', wfEscapeWikiText( $this->getTitle()->getPrefixedText() ) );
			return $status;
		}

		if ( !$dbw->cascadingDeletes() ) {
			$dbw->delete( 'revision', array( 'rev_page' => $id ), __METHOD__ );
		}

		// Clone the title, so we have the information we need when we log
		$logTitle = clone $this->mTitle;

		// Log the deletion, if the page was suppressed, log it at Oversight instead
		$logtype = $suppress ? 'suppress' : 'delete';

		$logEntry = new ManualLogEntry( $logtype, 'delete' );
		$logEntry->setPerformer( $user );
		$logEntry->setTarget( $logTitle );
		$logEntry->setComment( $reason );
		$logid = $logEntry->insert();

		$dbw->onTransactionPreCommitOrIdle( function () use ( $dbw, $logEntry, $logid ) {
			// Bug 56776: avoid deadlocks (especially from FileDeleteForm)
			$logEntry->publish( $logid );
		} );

		if ( $commit ) {
			$dbw->commit( __METHOD__ );
		}

		$this->doDeleteUpdates( $id, $content );

		Hooks::run( 'ArticleDeleteComplete', array( &$this, &$user, $reason, $id, $content, $logEntry ) );
		$status->value = $logid;
		return $status;
	}

	/**
	 * Lock the page row for this title and return page_latest (or 0)
	 *
	 * @return integer
	 */
	protected function lock() {
		return (int)wfGetDB( DB_MASTER )->selectField(
			'page',
			'page_latest',
			array(
				'page_namespace' => $this->getTitle()->getNamespace(),
				'page_title' => $this->getTitle()->getDBkey()
			),
			__METHOD__,
			array( 'FOR UPDATE' )
		);
	}

	/**
	 * Do some database updates after deletion
	 *
	 * @param int $id The page_id value of the page being deleted
	 * @param Content $content Optional page content to be used when determining
	 *   the required updates. This may be needed because $this->getContent()
	 *   may already return null when the page proper was deleted.
	 */
	public function doDeleteUpdates( $id, Content $content = null ) {
		// update site status
		DeferredUpdates::addUpdate( new SiteStatsUpdate( 0, 1, - (int)$this->isCountable(), -1 ) );

		// remove secondary indexes, etc
		$updates = $this->getDeletionUpdates( $content );
		DataUpdate::runUpdates( $updates );

		// Reparse any pages transcluding this page
		LinksUpdate::queueRecursiveJobsForTable( $this->mTitle, 'templatelinks' );

		// Reparse any pages including this image
		if ( $this->mTitle->getNamespace() == NS_FILE ) {
			LinksUpdate::queueRecursiveJobsForTable( $this->mTitle, 'imagelinks' );
		}

		// Clear caches
		WikiPage::onArticleDelete( $this->mTitle );

		// Reset this object and the Title object
		$this->loadFromRow( false, self::READ_LATEST );

		// Search engine
		DeferredUpdates::addUpdate( new SearchUpdate( $id, $this->mTitle ) );
	}

	/**
	 * Roll back the most recent consecutive set of edits to a page
	 * from the same user; fails if there are no eligible edits to
	 * roll back to, e.g. user is the sole contributor. This function
	 * performs permissions checks on $user, then calls commitRollback()
	 * to do the dirty work
	 *
	 * @todo Separate the business/permission stuff out from backend code
	 *
	 * @param string $fromP Name of the user whose edits to rollback.
	 * @param string $summary Custom summary. Set to default summary if empty.
	 * @param string $token Rollback token.
	 * @param bool $bot If true, mark all reverted edits as bot.
	 *
	 * @param array $resultDetails Array contains result-specific array of additional values
	 *    'alreadyrolled' : 'current' (rev)
	 *    success        : 'summary' (str), 'current' (rev), 'target' (rev)
	 *
	 * @param User $user The user performing the rollback
	 * @return array Array of errors, each error formatted as
	 *   array(messagekey, param1, param2, ...).
	 * On success, the array is empty.  This array can also be passed to
	 * OutputPage::showPermissionsErrorPage().
	 */
	public function doRollback(
		$fromP, $summary, $token, $bot, &$resultDetails, User $user
	) {
		$resultDetails = null;

		// Check permissions
		$editErrors = $this->mTitle->getUserPermissionsErrors( 'edit', $user );
		$rollbackErrors = $this->mTitle->getUserPermissionsErrors( 'rollback', $user );
		$errors = array_merge( $editErrors, wfArrayDiff2( $rollbackErrors, $editErrors ) );

		if ( !$user->matchEditToken( $token, array( $this->mTitle->getPrefixedText(), $fromP ) ) ) {
			$errors[] = array( 'sessionfailure' );
		}

		if ( $user->pingLimiter( 'rollback' ) || $user->pingLimiter() ) {
			$errors[] = array( 'actionthrottledtext' );
		}

		// If there were errors, bail out now
		if ( !empty( $errors ) ) {
			return $errors;
		}

		return $this->commitRollback( $fromP, $summary, $bot, $resultDetails, $user );
	}

	/**
	 * Backend implementation of doRollback(), please refer there for parameter
	 * and return value documentation
	 *
	 * NOTE: This function does NOT check ANY permissions, it just commits the
	 * rollback to the DB. Therefore, you should only call this function direct-
	 * ly if you want to use custom permissions checks. If you don't, use
	 * doRollback() instead.
	 * @param string $fromP Name of the user whose edits to rollback.
	 * @param string $summary Custom summary. Set to default summary if empty.
	 * @param bool $bot If true, mark all reverted edits as bot.
	 *
	 * @param array $resultDetails Contains result-specific array of additional values
	 * @param User $guser The user performing the rollback
	 * @return array
	 */
	public function commitRollback( $fromP, $summary, $bot, &$resultDetails, User $guser ) {
		global $wgUseRCPatrol, $wgContLang;

		$dbw = wfGetDB( DB_MASTER );

		if ( wfReadOnly() ) {
			return array( array( 'readonlytext' ) );
		}

		// Get the last editor
		$current = $this->getRevision();
		if ( is_null( $current ) ) {
			// Something wrong... no page?
			return array( array( 'notanarticle' ) );
		}

		$from = str_replace( '_', ' ', $fromP );
		// User name given should match up with the top revision.
		// If the user was deleted then $from should be empty.
		if ( $from != $current->getUserText() ) {
			$resultDetails = array( 'current' => $current );
			return array( array( 'alreadyrolled',
				htmlspecialchars( $this->mTitle->getPrefixedText() ),
				htmlspecialchars( $fromP ),
				htmlspecialchars( $current->getUserText() )
			) );
		}

		// Get the last edit not by this person...
		// Note: these may not be public values
		$user = intval( $current->getUser( Revision::RAW ) );
		$user_text = $dbw->addQuotes( $current->getUserText( Revision::RAW ) );
		$s = $dbw->selectRow( 'revision',
			array( 'rev_id', 'rev_timestamp', 'rev_deleted' ),
			array( 'rev_page' => $current->getPage(),
				"rev_user != {$user} OR rev_user_text != {$user_text}"
			), __METHOD__,
			array( 'USE INDEX' => 'page_timestamp',
				'ORDER BY' => 'rev_timestamp DESC' )
			);
		if ( $s === false ) {
			// No one else ever edited this page
			return array( array( 'cantrollback' ) );
		} elseif ( $s->rev_deleted & Revision::DELETED_TEXT
			|| $s->rev_deleted & Revision::DELETED_USER
		) {
			// Only admins can see this text
			return array( array( 'notvisiblerev' ) );
		}

		// Generate the edit summary if necessary
		$target = Revision::newFromId( $s->rev_id, Revision::READ_LATEST );
		if ( empty( $summary ) ) {
			if ( $from == '' ) { // no public user name
				$summary = wfMessage( 'revertpage-nouser' );
			} else {
				$summary = wfMessage( 'revertpage' );
			}
		}

		// Allow the custom summary to use the same args as the default message
		$args = array(
			$target->getUserText(), $from, $s->rev_id,
			$wgContLang->timeanddate( wfTimestamp( TS_MW, $s->rev_timestamp ) ),
			$current->getId(), $wgContLang->timeanddate( $current->getTimestamp() )
		);
		if ( $summary instanceof Message ) {
			$summary = $summary->params( $args )->inContentLanguage()->text();
		} else {
			$summary = wfMsgReplaceArgs( $summary, $args );
		}

		// Trim spaces on user supplied text
		$summary = trim( $summary );

		// Truncate for whole multibyte characters.
		$summary = $wgContLang->truncate( $summary, 255 );

		// Save
		$flags = EDIT_UPDATE;

		if ( $guser->isAllowed( 'minoredit' ) ) {
			$flags |= EDIT_MINOR;
		}

		if ( $bot && ( $guser->isAllowedAny( 'markbotedits', 'bot' ) ) ) {
			$flags |= EDIT_FORCE_BOT;
		}

		// Actually store the edit
		$status = $this->doEditContent(
			$target->getContent(),
			$summary,
			$flags,
			$target->getId(),
			$guser
		);

		// Set patrolling and bot flag on the edits, which gets rollbacked.
		// This is done even on edit failure to have patrolling in that case (bug 62157).
		$set = array();
		if ( $bot && $guser->isAllowed( 'markbotedits' ) ) {
			// Mark all reverted edits as bot
			$set['rc_bot'] = 1;
		}

		if ( $wgUseRCPatrol ) {
			// Mark all reverted edits as patrolled
			$set['rc_patrolled'] = 1;
		}

		if ( count( $set ) ) {
			$dbw->update( 'recentchanges', $set,
				array( /* WHERE */
					'rc_cur_id' => $current->getPage(),
					'rc_user_text' => $current->getUserText(),
					'rc_timestamp > ' . $dbw->addQuotes( $s->rev_timestamp ),
				),
				__METHOD__
			);
		}

		if ( !$status->isOK() ) {
			return $status->getErrorsArray();
		}

		// raise error, when the edit is an edit without a new version
		if ( empty( $status->value['revision'] ) ) {
			$resultDetails = array( 'current' => $current );
			return array( array( 'alreadyrolled',
					htmlspecialchars( $this->mTitle->getPrefixedText() ),
					htmlspecialchars( $fromP ),
					htmlspecialchars( $current->getUserText() )
			) );
		}

		$revId = $status->value['revision']->getId();

		Hooks::run( 'ArticleRollbackComplete', array( $this, $guser, $target, $current ) );

		$resultDetails = array(
			'summary' => $summary,
			'current' => $current,
			'target' => $target,
			'newid' => $revId
		);

		return array();
	}

	/**
	 * The onArticle*() functions are supposed to be a kind of hooks
	 * which should be called whenever any of the specified actions
	 * are done.
	 *
	 * This is a good place to put code to clear caches, for instance.
	 *
	 * This is called on page move and undelete, as well as edit
	 *
	 * @param Title $title
	 */
	public static function onArticleCreate( Title $title ) {
		// Update existence markers on article/talk tabs...
		$other = $title->getOtherPage();

		$other->purgeSquid();

		$title->touchLinks();
		$title->purgeSquid();
		$title->deleteTitleProtection();
	}

	/**
	 * Clears caches when article is deleted
	 *
	 * @param Title $title
	 */
	public static function onArticleDelete( Title $title ) {
		// Update existence markers on article/talk tabs...
		$other = $title->getOtherPage();

		$other->purgeSquid();

		$title->touchLinks();
		$title->purgeSquid();

		// File cache
		HTMLFileCache::clearFileCache( $title );
		InfoAction::invalidateCache( $title );

		// Messages
		if ( $title->getNamespace() == NS_MEDIAWIKI ) {
			MessageCache::singleton()->replace( $title->getDBkey(), false );
		}

		// Images
		if ( $title->getNamespace() == NS_FILE ) {
			$update = new HTMLCacheUpdate( $title, 'imagelinks' );
			$update->doUpdate();
		}

		// User talk pages
		if ( $title->getNamespace() == NS_USER_TALK ) {
			$user = User::newFromName( $title->getText(), false );
			if ( $user ) {
				$user->setNewtalk( false );
			}
		}

		// Image redirects
		RepoGroup::singleton()->getLocalRepo()->invalidateImageRedirect( $title );
	}

	/**
	 * Purge caches on page update etc
	 *
	 * @param Title $title
	 * @param Revision|null $revision Revision that was just saved, may be null
	 */
	public static function onArticleEdit( Title $title, Revision $revision = null ) {
		// Invalidate caches of articles which include this page
		DeferredUpdates::addHTMLCacheUpdate( $title, 'templatelinks' );

		// Invalidate the caches of all pages which redirect here
		DeferredUpdates::addHTMLCacheUpdate( $title, 'redirect' );

		// Purge squid for this page only
		$title->purgeSquid();
		// Clear file cache for this page only
		HTMLFileCache::clearFileCache( $title );

		$revid = $revision ? $revision->getId() : null;
		DeferredUpdates::addCallableUpdate( function() use ( $title, $revid ) {
			InfoAction::invalidateCache( $title, $revid );
		} );
	}

	/**#@-*/

	/**
	 * Returns a list of categories this page is a member of.
	 * Results will include hidden categories
	 *
	 * @return TitleArray
	 */
	public function getCategories() {
		$id = $this->getId();
		if ( $id == 0 ) {
			return TitleArray::newFromResult( new FakeResultWrapper( array() ) );
		}

		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select( 'categorylinks',
			array( 'cl_to AS page_title, ' . NS_CATEGORY . ' AS page_namespace' ),
			// Have to do that since DatabaseBase::fieldNamesWithAlias treats numeric indexes
			// as not being aliases, and NS_CATEGORY is numeric
			array( 'cl_from' => $id ),
			__METHOD__ );

		return TitleArray::newFromResult( $res );
	}

	/**
	 * Returns a list of hidden categories this page is a member of.
	 * Uses the page_props and categorylinks tables.
	 *
	 * @return array Array of Title objects
	 */
	public function getHiddenCategories() {
		$result = array();
		$id = $this->getId();

		if ( $id == 0 ) {
			return array();
		}

		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select( array( 'categorylinks', 'page_props', 'page' ),
			array( 'cl_to' ),
			array( 'cl_from' => $id, 'pp_page=page_id', 'pp_propname' => 'hiddencat',
				'page_namespace' => NS_CATEGORY, 'page_title=cl_to' ),
			__METHOD__ );

		if ( $res !== false ) {
			foreach ( $res as $row ) {
				$result[] = Title::makeTitle( NS_CATEGORY, $row->cl_to );
			}
		}

		return $result;
	}

	/**
	 * Return an applicable autosummary if one exists for the given edit.
	 * @param string|null $oldtext The previous text of the page.
	 * @param string|null $newtext The submitted text of the page.
	 * @param int $flags Bitmask: a bitmask of flags submitted for the edit.
	 * @return string An appropriate autosummary, or an empty string.
	 *
	 * @deprecated since 1.21, use ContentHandler::getAutosummary() instead
	 */
	public static function getAutosummary( $oldtext, $newtext, $flags ) {
		// NOTE: stub for backwards-compatibility. assumes the given text is
		// wikitext. will break horribly if it isn't.

		ContentHandler::deprecated( __METHOD__, '1.21' );

		$handler = ContentHandler::getForModelID( CONTENT_MODEL_WIKITEXT );
		$oldContent = is_null( $oldtext ) ? null : $handler->unserializeContent( $oldtext );
		$newContent = is_null( $newtext ) ? null : $handler->unserializeContent( $newtext );

		return $handler->getAutosummary( $oldContent, $newContent, $flags );
	}

	/**
	 * Auto-generates a deletion reason
	 *
	 * @param bool &$hasHistory Whether the page has a history
	 * @return string|bool String containing deletion reason or empty string, or boolean false
	 *    if no revision occurred
	 */
	public function getAutoDeleteReason( &$hasHistory ) {
		return $this->getContentHandler()->getAutoDeleteReason( $this->getTitle(), $hasHistory );
	}

	/**
	 * Update all the appropriate counts in the category table, given that
	 * we've added the categories $added and deleted the categories $deleted.
	 *
	 * @param array $added The names of categories that were added
	 * @param array $deleted The names of categories that were deleted
	 */
	public function updateCategoryCounts( array $added, array $deleted ) {
		$that = $this;
		$method = __METHOD__;
		$dbw = wfGetDB( DB_MASTER );

		// Do this at the end of the commit to reduce lock wait timeouts
		$dbw->onTransactionPreCommitOrIdle(
			function () use ( $dbw, $that, $method, $added, $deleted ) {
				$ns = $that->getTitle()->getNamespace();

				$addFields = array( 'cat_pages = cat_pages + 1' );
				$removeFields = array( 'cat_pages = cat_pages - 1' );
				if ( $ns == NS_CATEGORY ) {
					$addFields[] = 'cat_subcats = cat_subcats + 1';
					$removeFields[] = 'cat_subcats = cat_subcats - 1';
				} elseif ( $ns == NS_FILE ) {
					$addFields[] = 'cat_files = cat_files + 1';
					$removeFields[] = 'cat_files = cat_files - 1';
				}

				if ( count( $added ) ) {
					$insertRows = array();
					foreach ( $added as $cat ) {
						$insertRows[] = array(
							'cat_title'   => $cat,
							'cat_pages'   => 1,
							'cat_subcats' => ( $ns == NS_CATEGORY ) ? 1 : 0,
							'cat_files'   => ( $ns == NS_FILE ) ? 1 : 0,
						);
					}
					$dbw->upsert(
						'category',
						$insertRows,
						array( 'cat_title' ),
						$addFields,
						$method
					);
				}

				if ( count( $deleted ) ) {
					$dbw->update(
						'category',
						$removeFields,
						array( 'cat_title' => $deleted ),
						$method
					);
				}

				foreach ( $added as $catName ) {
					$cat = Category::newFromName( $catName );
					Hooks::run( 'CategoryAfterPageAdded', array( $cat, $that ) );
				}

				foreach ( $deleted as $catName ) {
					$cat = Category::newFromName( $catName );
					Hooks::run( 'CategoryAfterPageRemoved', array( $cat, $that ) );
				}
			}
		);
	}

	/**
	 * Opportunistically enqueue link update jobs given fresh parser output if useful
	 *
	 * @param ParserOutput $parserOutput Current version page output
	 * @since 1.25
	 */
	public function triggerOpportunisticLinksUpdate( ParserOutput $parserOutput ) {
		if ( wfReadOnly() ) {
			return;
		}

		if ( !Hooks::run( 'OpportunisticLinksUpdate', array( $this, $this->mTitle, $parserOutput ) ) ) {
			return;
		}

		if ( $this->mTitle->areRestrictionsCascading() ) {
			// If the page is cascade protecting, the links should really be up-to-date
			$params = array( 'prioritize' => true );
		} elseif ( $parserOutput->hasDynamicContent() ) {
			// Assume the output contains time/random based magic words
			$params = array();
		} else {
			// If the inclusions are deterministic, the edit-triggered link jobs are enough
			return;
		}

		// Check if the last link refresh was before page_touched
		if ( $this->getLinksTimestamp() < $this->getTouched() ) {
			$params['isOpportunistic'] = true;
			$params['rootJobTimestamp'] = $parserOutput->getCacheTime();

			JobQueueGroup::singleton()->lazyPush( EnqueueJob::newFromLocalJobs(
				new JobSpecification( 'refreshLinks', $params,
					array( 'removeDuplicates' => true ), $this->mTitle )
			) );
		}
	}

	/**
	 * Return a list of templates used by this article.
	 * Uses the templatelinks table
	 *
	 * @deprecated since 1.19; use Title::getTemplateLinksFrom()
	 * @return array Array of Title objects
	 */
	public function getUsedTemplates() {
		return $this->mTitle->getTemplateLinksFrom();
	}

	/**
	 * This function is called right before saving the wikitext,
	 * so we can do things like signatures and links-in-context.
	 *
	 * @deprecated since 1.19; use Parser::preSaveTransform() instead
	 * @param string $text Article contents
	 * @param User $user User doing the edit
	 * @param ParserOptions $popts Parser options, default options for
	 *   the user loaded if null given
	 * @return string Article contents with altered wikitext markup (signatures
	 * 	converted, {{subst:}}, templates, etc.)
	 */
	public function preSaveTransform( $text, User $user = null, ParserOptions $popts = null ) {
		global $wgParser, $wgUser;

		wfDeprecated( __METHOD__, '1.19' );

		$user = is_null( $user ) ? $wgUser : $user;

		if ( $popts === null ) {
			$popts = ParserOptions::newFromUser( $user );
		}

		return $wgParser->preSaveTransform( $text, $this->mTitle, $user, $popts );
	}

	/**
	 * Update the article's restriction field, and leave a log entry.
	 *
	 * @deprecated since 1.19
	 * @param array $limit Set of restriction keys
	 * @param string $reason
	 * @param int &$cascade Set to false if cascading protection isn't allowed.
	 * @param array $expiry Per restriction type expiration
	 * @param User $user The user updating the restrictions
	 * @return bool True on success
	 */
	public function updateRestrictions(
		$limit = array(), $reason = '', &$cascade = 0, $expiry = array(), User $user = null
	) {
		global $wgUser;

		$user = is_null( $user ) ? $wgUser : $user;

		return $this->doUpdateRestrictions( $limit, $expiry, $cascade, $reason, $user )->isOK();
	}

	/**
	 * Returns a list of updates to be performed when this page is deleted. The
	 * updates should remove any information about this page from secondary data
	 * stores such as links tables.
	 *
	 * @param Content|null $content Optional Content object for determining the
	 *   necessary updates.
	 * @return array An array of DataUpdates objects
	 */
	public function getDeletionUpdates( Content $content = null ) {
		if ( !$content ) {
			// load content object, which may be used to determine the necessary updates
			// XXX: the content may not be needed to determine the updates, then this would be overhead.
			$content = $this->getContent( Revision::RAW );
		}

		if ( !$content ) {
			$updates = array();
		} else {
			$updates = $content->getDeletionUpdates( $this );
		}

		Hooks::run( 'WikiPageDeletionUpdates', array( $this, $content, &$updates ) );
		return $updates;
	}
}
