( function ( mw, $ ) {
	var formatText, formatParse, formatnumTests, specialCharactersPageName, expectedListUsers, expectedEntrypoints,
		mwLanguageCache = {},
		hasOwn = Object.hasOwnProperty;

	// When the expected result is the same in both modes
	function assertBothModes( assert, parserArguments, expectedResult, assertMessage ) {
		assert.equal( formatText.apply( null, parserArguments ), expectedResult, assertMessage + ' when format is \'text\'' );
		assert.equal( formatParse.apply( null, parserArguments ), expectedResult, assertMessage + ' when format is \'parse\'' );
	}

	QUnit.module( 'mediawiki.jqueryMsg', QUnit.newMwEnvironment( {
		setup: function () {
			this.originalMwLanguage = mw.language;

			specialCharactersPageName = '"Who" wants to be a millionaire & live on \'Exotic Island\'?';

			expectedListUsers = '注册<a title="Special:ListUsers" href="/wiki/Special:ListUsers">用户</a>';

			expectedEntrypoints = '<a href="https://www.mediawiki.org/wiki/Manual:index.php">index.php</a>';

			formatText = mw.jqueryMsg.getMessageFunction( {
				format: 'text'
			} );

			formatParse = mw.jqueryMsg.getMessageFunction( {
				format: 'parse'
			} );
		},
		teardown: function () {
			mw.language = this.originalMwLanguage;
		},
		config: {
			wgArticlePath: '/wiki/$1'
		},
		// Messages that are reused in multiple tests
		messages: {
			// The values for gender are not significant,
			// what matters is which of the values is choosen by the parser
			'gender-msg': '$1: {{GENDER:$2|blue|pink|green}}',
			'gender-msg-currentuser': '{{GENDER:|blue|pink|green}}',

			'plural-msg': 'Found $1 {{PLURAL:$1|item|items}}',
			// See https://bugzilla.wikimedia.org/69993
			'plural-msg-explicit-forms-nested': 'Found {{PLURAL:$1|$1 results|0=no results in {{SITENAME}}|1=$1 result}}',
			// Assume the grammar form grammar_case_foo is not valid in any language
			'grammar-msg': 'Przeszukaj {{GRAMMAR:grammar_case_foo|{{SITENAME}}}}',

			'formatnum-msg': '{{formatnum:$1}}',

			'portal-url': 'Project:Community portal',
			'see-portal-url': '{{Int:portal-url}} is an important community page.',

			'jquerymsg-test-statistics-users': '注册[[Special:ListUsers|用户]]',

			'jquerymsg-test-version-entrypoints-index-php': '[https://www.mediawiki.org/wiki/Manual:index.php index.php]',

			'external-link-replace': 'Foo [$1 bar]',
			'external-link-plural': 'Foo {{PLURAL:$1|is [$2 one]|are [$2 some]|2=[$2 two]|3=three|4=a=b|5=}} things.',
			'plural-only-explicit-forms': 'It is a {{PLURAL:$1|1=single|2=double}} room.'
		}
	} ) );

	/**
	 * Be careful to no run this in parallel as it uses a global identifier (mw.language)
	 * to transport the module back to the test. It musn't be overwritten concurrentely.
	 *
	 * This function caches the mw.language data to avoid having to request the same module
	 * multiple times. There is more than one test case for any given language.
	 */
	function getMwLanguage( langCode ) {
		if ( !hasOwn.call( mwLanguageCache, langCode ) ) {
			mwLanguageCache[ langCode ] = $.ajax( {
				url: mw.util.wikiScript( 'load' ),
				data: {
					skin: mw.config.get( 'skin' ),
					lang: langCode,
					debug: mw.config.get( 'debug' ),
					modules: [
						'mediawiki.language.data',
						'mediawiki.language'
					].join( '|' ),
					only: 'scripts'
				},
				dataType: 'script',
				cache: true
			} ).then( function () {
				return mw.language;
			} );
		}
		return mwLanguageCache[ langCode ];
	}

	/**
	 * @param {Function[]} tasks List of functions that perform tasks
	 *  that may be asynchronous. Invoke the callback parameter when done.
	 * @param {Function} complete Called when all tasks are done, or when the sequence is aborted.
	 */
	function process( tasks, complete ) {
		/*jshint latedef:false */
		function abort() {
			tasks.splice( 0, tasks.length );
			next();
		}
		function next() {
			if ( !tasks ) {
				// This happens if after the process is completed, one of our callbacks is
				// invoked. This can happen if a test timed out but the process was still
				// running. In that case, ignore it. Don't invoke complete() a second time.
				return;
			}
			var task = tasks.shift();
			if ( task ) {
				task( next, abort );
			} else {
				// Remove tasks list to indicate the process is final.
				tasks = null;
				complete();
			}
		}
		next();
	}

	QUnit.test( 'Replace', 16, function ( assert ) {
		mw.messages.set( 'simple', 'Foo $1 baz $2' );

		assert.equal( formatParse( 'simple' ), 'Foo $1 baz $2', 'Replacements with no substitutes' );
		assert.equal( formatParse( 'simple', 'bar' ), 'Foo bar baz $2', 'Replacements with less substitutes' );
		assert.equal( formatParse( 'simple', 'bar', 'quux' ), 'Foo bar baz quux', 'Replacements with all substitutes' );

		mw.messages.set( 'plain-input', '<foo foo="foo">x$1y&lt;</foo>z' );

		assert.equal(
			formatParse( 'plain-input', 'bar' ),
			'&lt;foo foo="foo"&gt;xbary&amp;lt;&lt;/foo&gt;z',
			'Input is not considered html'
		);

		mw.messages.set( 'plain-replace', 'Foo $1' );

		assert.equal(
			formatParse( 'plain-replace', '<bar bar="bar">&gt;</bar>' ),
			'Foo &lt;bar bar="bar"&gt;&amp;gt;&lt;/bar&gt;',
			'Replacement is not considered html'
		);

		mw.messages.set( 'object-replace', 'Foo $1' );

		assert.equal(
			formatParse( 'object-replace', $( '<div class="bar">&gt;</div>' ) ),
			'Foo <div class="bar">&gt;</div>',
			'jQuery objects are preserved as raw html'
		);

		assert.equal(
			formatParse( 'object-replace', $( '<div class="bar">&gt;</div>' ).get( 0 ) ),
			'Foo <div class="bar">&gt;</div>',
			'HTMLElement objects are preserved as raw html'
		);

		assert.equal(
			formatParse( 'object-replace', $( '<div class="bar">&gt;</div>' ).toArray() ),
			'Foo <div class="bar">&gt;</div>',
			'HTMLElement[] arrays are preserved as raw html'
		);

		assert.equal(
			formatParse( 'external-link-replace', 'http://example.org/?x=y&z' ),
			'Foo <a href="http://example.org/?x=y&amp;z">bar</a>',
			'Href is not double-escaped in wikilink function'
		);
		assert.equal(
			formatParse( 'external-link-plural', 1, 'http://example.org' ),
			'Foo is <a href="http://example.org">one</a> things.',
			'Link is expanded inside plural and is not escaped html'
		);
		assert.equal(
			formatParse( 'external-link-plural', 2, 'http://example.org' ),
			'Foo <a href=\"http://example.org\">two</a> things.',
			'Link is expanded inside an explicit plural form and is not escaped html'
		);
		assert.equal(
			formatParse( 'external-link-plural', 3 ),
			'Foo three things.',
			'A simple explicit plural form co-existing with complex explicit plural forms'
		);
		assert.equal(
			formatParse( 'external-link-plural', 4, 'http://example.org' ),
			'Foo a=b things.',
			'Only first equal sign is used as delimiter for explicit plural form. Repeated equal signs does not create issue'
		);
		assert.equal(
			formatParse( 'external-link-plural', 5, 'http://example.org' ),
			'Foo are <a href="http://example.org">some</a> things.',
			'Invalid explicit plural form. Plural fallback to the "other" plural form'
		);
		assert.equal(
			formatParse( 'external-link-plural', 6, 'http://example.org' ),
			'Foo are <a href="http://example.org">some</a> things.',
			'Plural fallback to the "other" plural form'
		);
		assert.equal(
			formatParse( 'plural-only-explicit-forms', 2 ),
			'It is a double room.',
			'Plural with explicit forms alone.'
		);
	} );

	QUnit.test( 'Plural', 6, function ( assert ) {
		assert.equal( formatParse( 'plural-msg', 0 ), 'Found 0 items', 'Plural test for english with zero as count' );
		assert.equal( formatParse( 'plural-msg', 1 ), 'Found 1 item', 'Singular test for english' );
		assert.equal( formatParse( 'plural-msg', 2 ), 'Found 2 items', 'Plural test for english' );
		assert.equal( formatParse( 'plural-msg-explicit-forms-nested', 6 ), 'Found 6 results', 'Plural message with explicit plural forms' );
		assert.equal( formatParse( 'plural-msg-explicit-forms-nested', 0 ), 'Found no results in ' + mw.config.get( 'wgSiteName' ), 'Plural message with explicit plural forms, with nested {{SITENAME}}' );
		assert.equal( formatParse( 'plural-msg-explicit-forms-nested', 1 ), 'Found 1 result', 'Plural message with explicit plural forms with placeholder nested' );
	} );

	QUnit.test( 'Gender', 15, function ( assert ) {
		var originalGender = mw.user.options.get( 'gender' );

		// TODO: These tests should be for mw.msg once mw.msg integrated with mw.jqueryMsg
		// TODO: English may not be the best language for these tests. Use a language like Arabic or Russian
		mw.user.options.set( 'gender', 'male' );
		assert.equal(
			formatParse( 'gender-msg', 'Bob', 'male' ),
			'Bob: blue',
			'Masculine from string "male"'
		);
		assert.equal(
			formatParse( 'gender-msg', 'Bob', mw.user ),
			'Bob: blue',
			'Masculine from mw.user object'
		);
		assert.equal(
			formatParse( 'gender-msg-currentuser' ),
			'blue',
			'Masculine for current user'
		);

		mw.user.options.set( 'gender', 'female' );
		assert.equal(
			formatParse( 'gender-msg', 'Alice', 'female' ),
			'Alice: pink',
			'Feminine from string "female"' );
		assert.equal(
			formatParse( 'gender-msg', 'Alice', mw.user ),
			'Alice: pink',
			'Feminine from mw.user object'
		);
		assert.equal(
			formatParse( 'gender-msg-currentuser' ),
			'pink',
			'Feminine for current user'
		);

		mw.user.options.set( 'gender', 'unknown' );
		assert.equal(
			formatParse( 'gender-msg', 'Foo', mw.user ),
			'Foo: green',
			'Neutral from mw.user object' );
		assert.equal(
			formatParse( 'gender-msg', 'User' ),
			'User: green',
			'Neutral when no parameter given' );
		assert.equal(
			formatParse( 'gender-msg', 'User', 'unknown' ),
			'User: green',
			'Neutral from string "unknown"'
		);
		assert.equal(
			formatParse( 'gender-msg-currentuser' ),
			'green',
			'Neutral for current user'
		);

		mw.messages.set( 'gender-msg-one-form', '{{GENDER:$1|User}}: $2 {{PLURAL:$2|edit|edits}}' );

		assert.equal(
			formatParse( 'gender-msg-one-form', 'male', 10 ),
			'User: 10 edits',
			'Gender neutral and plural form'
		);
		assert.equal(
			formatParse( 'gender-msg-one-form', 'female', 1 ),
			'User: 1 edit',
			'Gender neutral and singular form'
		);

		mw.messages.set( 'gender-msg-lowercase', '{{gender:$1|he|she}} is awesome' );
		assert.equal(
			formatParse( 'gender-msg-lowercase', 'male' ),
			'he is awesome',
			'Gender masculine'
		);
		assert.equal(
			formatParse( 'gender-msg-lowercase', 'female' ),
			'she is awesome',
			'Gender feminine'
		);

		mw.messages.set( 'gender-msg-wrong', '{{gender}} test' );
		assert.equal(
			formatParse( 'gender-msg-wrong', 'female' ),
			' test',
			'Invalid syntax should result in {{gender}} simply being stripped away'
		);

		mw.user.options.set( 'gender', originalGender );
	} );

	QUnit.test( 'Grammar', 2, function ( assert ) {
		assert.equal( formatParse( 'grammar-msg' ), 'Przeszukaj ' + mw.config.get( 'wgSiteName' ), 'Grammar Test with sitename' );

		mw.messages.set( 'grammar-msg-wrong-syntax', 'Przeszukaj {{GRAMMAR:grammar_case_xyz}}' );
		assert.equal( formatParse( 'grammar-msg-wrong-syntax' ), 'Przeszukaj ', 'Grammar Test with wrong grammar template syntax' );
	} );

	QUnit.test( 'Match PHP parser', mw.libs.phpParserData.tests.length, function ( assert ) {
		mw.messages.set( mw.libs.phpParserData.messages );
		var tasks = $.map( mw.libs.phpParserData.tests, function ( test ) {
			return function ( next, abort ) {
				getMwLanguage( test.lang )
					.then( function ( langClass ) {
						mw.config.set( 'wgUserLanguage', test.lang );
						var parser = new mw.jqueryMsg.parser( { language: langClass } );
						assert.equal(
							parser.parse( test.key, test.args ).html(),
							test.result,
							test.name
						);
					}, function () {
						assert.ok( false, 'Language "' + test.lang + '" failed to load.' );
					} )
					.then( next, abort );
			};
		} );

		QUnit.stop();
		process( tasks, QUnit.start );
	} );

	QUnit.test( 'Links', 6, function ( assert ) {
		var expectedDisambiguationsText,
			expectedMultipleBars,
			expectedSpecialCharacters;

		// The below three are all identical to or based on real messages.  For disambiguations-text,
		// the bold was removed because it is not yet implemented.

		assert.htmlEqual(
			formatParse( 'jquerymsg-test-statistics-users' ),
			expectedListUsers,
			'Piped wikilink'
		);

		expectedDisambiguationsText = 'The following pages contain at least one link to a disambiguation page.\nThey may have to link to a more appropriate page instead.\nA page is treated as a disambiguation page if it uses a template that is linked from ' +
			'<a title="MediaWiki:Disambiguationspage" href="/wiki/MediaWiki:Disambiguationspage">MediaWiki:Disambiguationspage</a>.';

		mw.messages.set( 'disambiguations-text', 'The following pages contain at least one link to a disambiguation page.\nThey may have to link to a more appropriate page instead.\nA page is treated as a disambiguation page if it uses a template that is linked from [[MediaWiki:Disambiguationspage]].' );
		assert.htmlEqual(
			formatParse( 'disambiguations-text' ),
			expectedDisambiguationsText,
			'Wikilink without pipe'
		);

		assert.htmlEqual(
			formatParse( 'jquerymsg-test-version-entrypoints-index-php' ),
			expectedEntrypoints,
			'External link'
		);

		// Pipe trick is not supported currently, but should not parse as text either.
		mw.messages.set( 'pipe-trick', '[[Tampa, Florida|]]' );
		this.suppressWarnings();
		assert.equal(
			formatParse( 'pipe-trick' ),
			'[[Tampa, Florida|]]',
			'Pipe trick should not be parsed.'
		);
		this.restoreWarnings();

		expectedMultipleBars = '<a title="Main Page" href="/wiki/Main_Page">Main|Page</a>';
		mw.messages.set( 'multiple-bars', '[[Main Page|Main|Page]]' );
		assert.htmlEqual(
			formatParse( 'multiple-bars' ),
			expectedMultipleBars,
			'Bar in anchor'
		);

		expectedSpecialCharacters = '<a title="&quot;Who&quot; wants to be a millionaire &amp; live on &#039;Exotic Island&#039;?" href="/wiki/%22Who%22_wants_to_be_a_millionaire_%26_live_on_&#039;Exotic_Island&#039;%3F">&quot;Who&quot; wants to be a millionaire &amp; live on &#039;Exotic Island&#039;?</a>';

		mw.messages.set( 'special-characters', '[[' + specialCharactersPageName + ']]' );
		assert.htmlEqual(
			formatParse( 'special-characters' ),
			expectedSpecialCharacters,
			'Special characters'
		);
	} );

	// Tests that {{-transformation vs. general parsing are done as requested
	QUnit.test( 'Curly brace transformation', 16, function ( assert ) {
		var oldUserLang = mw.config.get( 'wgUserLanguage' );

		assertBothModes( assert, [ 'gender-msg', 'Bob', 'male' ], 'Bob: blue', 'gender is resolved' );

		assertBothModes( assert, [ 'plural-msg', 5 ], 'Found 5 items', 'plural is resolved' );

		assertBothModes( assert, [ 'grammar-msg' ], 'Przeszukaj ' + mw.config.get( 'wgSiteName' ), 'grammar is resolved' );

		mw.config.set( 'wgUserLanguage', 'en' );
		assertBothModes( assert, [ 'formatnum-msg', '987654321.654321' ], '987,654,321.654', 'formatnum is resolved' );

		// Test non-{{ wikitext, where behavior differs

		// Wikilink
		assert.equal(
			formatText( 'jquerymsg-test-statistics-users' ),
			mw.messages.get( 'jquerymsg-test-statistics-users' ),
			'Internal link message unchanged when format is \'text\''
		);
		assert.htmlEqual(
			formatParse( 'jquerymsg-test-statistics-users' ),
			expectedListUsers,
			'Internal link message parsed when format is \'parse\''
		);

		// External link
		assert.equal(
			formatText( 'jquerymsg-test-version-entrypoints-index-php' ),
			mw.messages.get( 'jquerymsg-test-version-entrypoints-index-php' ),
			'External link message unchanged when format is \'text\''
		);
		assert.htmlEqual(
			formatParse( 'jquerymsg-test-version-entrypoints-index-php' ),
			expectedEntrypoints,
			'External link message processed when format is \'parse\''
		);

		// External link with parameter
		assert.equal(
			formatText( 'external-link-replace', 'http://example.com' ),
			'Foo [http://example.com bar]',
			'External link message only substitutes parameter when format is \'text\''
		);
		assert.htmlEqual(
			formatParse( 'external-link-replace', 'http://example.com' ),
			'Foo <a href="http://example.com">bar</a>',
			'External link message processed when format is \'parse\''
		);
		assert.htmlEqual(
			formatParse( 'external-link-replace', $( '<i>' ) ),
			'Foo <i>bar</i>',
			'External link message processed as jQuery object when format is \'parse\''
		);
		assert.htmlEqual(
			formatParse( 'external-link-replace', function () {} ),
			'Foo <a href="#">bar</a>',
			'External link message processed as function when format is \'parse\''
		);

		mw.config.set( 'wgUserLanguage', oldUserLang );
	} );

	QUnit.test( 'Int', 4, function ( assert ) {
		var newarticletextSource = 'You have followed a link to a page that does not exist yet. To create the page, start typing in the box below (see the [[{{Int:Foobar}}|foobar]] for more info). If you are here by mistake, click your browser\'s back button.',
			expectedNewarticletext,
			helpPageTitle = 'Help:Foobar';

		mw.messages.set( 'foobar', helpPageTitle );

		expectedNewarticletext = 'You have followed a link to a page that does not exist yet. To create the page, start typing in the box below (see the ' +
			'<a title="Help:Foobar" href="/wiki/Help:Foobar">foobar</a> for more info). If you are here by mistake, click your browser\'s back button.';

		mw.messages.set( 'newarticletext', newarticletextSource );

		assert.htmlEqual(
			formatParse( 'newarticletext' ),
			expectedNewarticletext,
			'Link with nested message'
		);

		assert.equal(
			formatParse( 'see-portal-url' ),
			'Project:Community portal is an important community page.',
			'Nested message'
		);

		mw.messages.set( 'newarticletext-lowercase',
			newarticletextSource.replace( 'Int:Helppage', 'int:helppage' ) );

		assert.htmlEqual(
			formatParse( 'newarticletext-lowercase' ),
			expectedNewarticletext,
			'Link with nested message, lowercase include'
		);

		mw.messages.set( 'uses-missing-int', '{{int:doesnt-exist}}' );

		assert.equal(
			formatParse( 'uses-missing-int' ),
			'[doesnt-exist]',
			'int: where nested message does not exist'
		);
	} );

	// Tests that getMessageFunction is used for non-plain messages with curly braces or
	// square brackets, but not otherwise.
	QUnit.test( 'mw.Message.prototype.parser monkey-patch', 22, function ( assert ) {
		var oldGMF, outerCalled, innerCalled;

		mw.messages.set( {
			'curly-brace': '{{int:message}}',
			'single-square-bracket': '[https://www.mediawiki.org/ MediaWiki]',
			'double-square-bracket': '[[Some page]]',
			regular: 'Other message'
		} );

		oldGMF = mw.jqueryMsg.getMessageFunction;

		mw.jqueryMsg.getMessageFunction = function () {
			outerCalled = true;
			return function () {
				innerCalled = true;
			};
		};

		function verifyGetMessageFunction( key, format, shouldCall ) {
			var message;
			outerCalled = false;
			innerCalled = false;
			message = mw.message( key );
			message[ format ]();
			assert.strictEqual( outerCalled, shouldCall, 'Outer function called for ' + key );
			assert.strictEqual( innerCalled, shouldCall, 'Inner function called for ' + key );
		}

		verifyGetMessageFunction( 'curly-brace', 'parse', true );
		verifyGetMessageFunction( 'curly-brace', 'plain', false );

		verifyGetMessageFunction( 'single-square-bracket', 'parse', true );
		verifyGetMessageFunction( 'single-square-bracket', 'plain', false );

		verifyGetMessageFunction( 'double-square-bracket', 'parse', true );
		verifyGetMessageFunction( 'double-square-bracket', 'plain', false );

		verifyGetMessageFunction( 'regular', 'parse', false );
		verifyGetMessageFunction( 'regular', 'plain', false );

		verifyGetMessageFunction( 'jquerymsg-test-pagetriage-del-talk-page-notify-summary', 'plain', false );
		verifyGetMessageFunction( 'jquerymsg-test-categorytree-collapse-bullet', 'plain', false );
		verifyGetMessageFunction( 'jquerymsg-test-wikieditor-toolbar-help-content-signature-result', 'plain', false );

		mw.jqueryMsg.getMessageFunction = oldGMF;
	} );

	formatnumTests = [
		{
			lang: 'en',
			number: 987654321.654321,
			result: '987,654,321.654',
			description: 'formatnum test for English, decimal separator'
		},
		{
			lang: 'ar',
			number: 987654321.654321,
			result: '٩٨٧٬٦٥٤٬٣٢١٫٦٥٤',
			description: 'formatnum test for Arabic, with decimal separator'
		},
		{
			lang: 'ar',
			number: '٩٨٧٦٥٤٣٢١٫٦٥٤٣٢١',
			result: 987654321,
			integer: true,
			description: 'formatnum test for Arabic, with decimal separator, reverse'
		},
		{
			lang: 'ar',
			number: -12.89,
			result: '-١٢٫٨٩',
			description: 'formatnum test for Arabic, negative number'
		},
		{
			lang: 'ar',
			number: '-١٢٫٨٩',
			result: -12,
			integer: true,
			description: 'formatnum test for Arabic, negative number, reverse'
		},
		{
			lang: 'nl',
			number: 987654321.654321,
			result: '987.654.321,654',
			description: 'formatnum test for Nederlands, decimal separator'
		},
		{
			lang: 'nl',
			number: -12.89,
			result: '-12,89',
			description: 'formatnum test for Nederlands, negative number'
		},
		{
			lang: 'nl',
			number: '.89',
			result: '0,89',
			description: 'formatnum test for Nederlands'
		},
		{
			lang: 'nl',
			number: 'invalidnumber',
			result: 'invalidnumber',
			description: 'formatnum test for Nederlands, invalid number'
		},
		{
			lang: 'ml',
			number: '1000000000',
			result: '1,00,00,00,000',
			description: 'formatnum test for Malayalam'
		},
		{
			lang: 'ml',
			number: '-1000000000',
			result: '-1,00,00,00,000',
			description: 'formatnum test for Malayalam, negative number'
		},
		/*
		 * This will fail because of wrong pattern for ml in MW(different from CLDR)
		{
			lang: 'ml',
			number: '1000000000.000',
			result: '1,00,00,00,000.000',
			description: 'formatnum test for Malayalam with decimal place'
		},
		*/
		{
			lang: 'hi',
			number: '123456789.123456789',
			result: '१२,३४,५६,७८९',
			description: 'formatnum test for Hindi'
		},
		{
			lang: 'hi',
			number: '१२,३४,५६,७८९',
			result: '१२,३४,५६,७८९',
			description: 'formatnum test for Hindi, Devanagari digits passed'
		},
		{
			lang: 'hi',
			number: '१२३४५६,७८९',
			result: '123456',
			integer: true,
			description: 'formatnum test for Hindi, Devanagari digits passed to get integer value'
		}
	];

	QUnit.test( 'formatnum', formatnumTests.length, function ( assert ) {
		mw.messages.set( 'formatnum-msg', '{{formatnum:$1}}' );
		mw.messages.set( 'formatnum-msg-int', '{{formatnum:$1|R}}' );
		var queue = $.map( formatnumTests, function ( test ) {
			return function ( next, abort ) {
				getMwLanguage( test.lang )
					.then( function ( langClass ) {
						mw.config.set( 'wgUserLanguage', test.lang );
						var parser = new mw.jqueryMsg.parser( { language: langClass } );
						assert.equal(
							parser.parse( test.integer ? 'formatnum-msg-int' : 'formatnum-msg',
								[ test.number ] ).html(),
							test.result,
							test.description
						);
					}, function () {
						assert.ok( false, 'Language "' + test.lang + '" failed to load' );
					} )
					.then( next, abort );
			};
		} );
		QUnit.stop();
		process( queue, QUnit.start );
	} );

	// HTML in wikitext
	QUnit.test( 'HTML', 26, function ( assert ) {
		mw.messages.set( 'jquerymsg-italics-msg', '<i>Very</i> important' );

		assertBothModes( assert, [ 'jquerymsg-italics-msg' ], mw.messages.get( 'jquerymsg-italics-msg' ), 'Simple italics unchanged' );

		mw.messages.set( 'jquerymsg-bold-msg', '<b>Strong</b> speaker' );
		assertBothModes( assert, [ 'jquerymsg-bold-msg' ], mw.messages.get( 'jquerymsg-bold-msg' ), 'Simple bold unchanged' );

		mw.messages.set( 'jquerymsg-bold-italics-msg', 'It is <b><i>key</i></b>' );
		assertBothModes( assert, [ 'jquerymsg-bold-italics-msg' ], mw.messages.get( 'jquerymsg-bold-italics-msg' ), 'Bold and italics nesting order preserved' );

		mw.messages.set( 'jquerymsg-italics-bold-msg', 'It is <i><b>vital</b></i>' );
		assertBothModes( assert, [ 'jquerymsg-italics-bold-msg' ], mw.messages.get( 'jquerymsg-italics-bold-msg' ), 'Italics and bold nesting order preserved' );

		mw.messages.set( 'jquerymsg-italics-with-link', 'An <i>italicized [[link|wiki-link]]</i>' );

		assert.htmlEqual(
			formatParse( 'jquerymsg-italics-with-link' ),
			'An <i>italicized <a title="link" href="' + mw.html.escape( mw.util.getUrl( 'link' ) ) + '">wiki-link</i>',
			'Italics with link inside in parse mode'
		);

		assert.equal(
			formatText( 'jquerymsg-italics-with-link' ),
			mw.messages.get( 'jquerymsg-italics-with-link' ),
			'Italics with link unchanged in text mode'
		);

		mw.messages.set( 'jquerymsg-italics-id-class', '<i id="foo" class="bar">Foo</i>' );
		assert.htmlEqual(
			formatParse( 'jquerymsg-italics-id-class' ),
			mw.messages.get( 'jquerymsg-italics-id-class' ),
			'ID and class are allowed'
		);

		mw.messages.set( 'jquerymsg-italics-onclick', '<i onclick="alert(\'foo\')">Foo</i>' );
		assert.htmlEqual(
			formatParse( 'jquerymsg-italics-onclick' ),
			'&lt;i onclick=&quot;alert(\'foo\')&quot;&gt;Foo&lt;/i&gt;',
			'element with onclick is escaped because it is not allowed'
		);

		mw.messages.set( 'jquerymsg-script-msg', '<script  >alert( "Who put this tag here?" );</script>' );
		assert.htmlEqual(
			formatParse( 'jquerymsg-script-msg' ),
			'&lt;script  &gt;alert( &quot;Who put this tag here?&quot; );&lt;/script&gt;',
			'Tag outside whitelist escaped in parse mode'
		);

		assert.equal(
			formatText( 'jquerymsg-script-msg' ),
			mw.messages.get( 'jquerymsg-script-msg' ),
			'Tag outside whitelist unchanged in text mode'
		);

		mw.messages.set( 'jquerymsg-script-link-msg', '<script>[[Foo|bar]]</script>' );
		assert.htmlEqual(
			formatParse( 'jquerymsg-script-link-msg' ),
			'&lt;script&gt;<a title="Foo" href="' + mw.html.escape( mw.util.getUrl( 'Foo' ) ) + '">bar</a>&lt;/script&gt;',
			'Script tag text is escaped because that element is not allowed, but link inside is still HTML'
		);

		mw.messages.set( 'jquerymsg-mismatched-html', '<i class="important">test</b>' );
		assert.htmlEqual(
			formatParse( 'jquerymsg-mismatched-html' ),
			'&lt;i class=&quot;important&quot;&gt;test&lt;/b&gt;',
			'Mismatched HTML start and end tag treated as text'
		);

		// TODO (mattflaschen, 2013-03-18): It's not a security issue, but there's no real
		// reason the htmlEmitter span needs to be here. It's an artifact of how emitting works.
		mw.messages.set( 'jquerymsg-script-and-external-link', '<script>alert( "jquerymsg-script-and-external-link test" );</script> [http://example.com <i>Foo</i> bar]' );
		assert.htmlEqual(
			formatParse( 'jquerymsg-script-and-external-link' ),
			'&lt;script&gt;alert( "jquerymsg-script-and-external-link test" );&lt;/script&gt; <a href="http://example.com"><span class="mediaWiki_htmlEmitter"><i>Foo</i> bar</span></a>',
			'HTML tags in external links not interfering with escaping of other tags'
		);

		mw.messages.set( 'jquerymsg-link-script', '[http://example.com <script>alert( "jquerymsg-link-script test" );</script>]' );
		assert.htmlEqual(
			formatParse( 'jquerymsg-link-script' ),
			'<a href="http://example.com"><span class="mediaWiki_htmlEmitter">&lt;script&gt;alert( "jquerymsg-link-script test" );&lt;/script&gt;</span></a>',
			'Non-whitelisted HTML tag in external link anchor treated as text'
		);

		// Intentionally not using htmlEqual for the quote tests
		mw.messages.set( 'jquerymsg-double-quotes-preserved', '<i id="double">Double</i>' );
		assert.equal(
			formatParse( 'jquerymsg-double-quotes-preserved' ),
			mw.messages.get( 'jquerymsg-double-quotes-preserved' ),
			'Attributes with double quotes are preserved as such'
		);

		mw.messages.set( 'jquerymsg-single-quotes-normalized-to-double', '<i id=\'single\'>Single</i>' );
		assert.equal(
			formatParse( 'jquerymsg-single-quotes-normalized-to-double' ),
			'<i id="single">Single</i>',
			'Attributes with single quotes are normalized to double'
		);

		mw.messages.set( 'jquerymsg-escaped-double-quotes-attribute', '<i style="font-family:&quot;Arial&quot;">Styled</i>' );
		assert.htmlEqual(
			formatParse( 'jquerymsg-escaped-double-quotes-attribute' ),
			mw.messages.get( 'jquerymsg-escaped-double-quotes-attribute' ),
			'Escaped attributes are parsed correctly'
		);

		mw.messages.set( 'jquerymsg-escaped-single-quotes-attribute', '<i style=\'font-family:&#039;Arial&#039;\'>Styled</i>' );
		assert.htmlEqual(
			formatParse( 'jquerymsg-escaped-single-quotes-attribute' ),
			mw.messages.get( 'jquerymsg-escaped-single-quotes-attribute' ),
			'Escaped attributes are parsed correctly'
		);

		mw.messages.set( 'jquerymsg-wikitext-contents-parsed', '<i>[http://example.com Example]</i>' );
		assert.htmlEqual(
			formatParse( 'jquerymsg-wikitext-contents-parsed' ),
			'<i><a href="http://example.com">Example</a></i>',
			'Contents of valid tag are treated as wikitext, so external link is parsed'
		);

		mw.messages.set( 'jquerymsg-wikitext-contents-script', '<i><script>Script inside</script></i>' );
		assert.htmlEqual(
			formatParse( 'jquerymsg-wikitext-contents-script' ),
			'<i><span class="mediaWiki_htmlEmitter">&lt;script&gt;Script inside&lt;/script&gt;</span></i>',
			'Contents of valid tag are treated as wikitext, so invalid HTML element is treated as text'
		);

		mw.messages.set( 'jquerymsg-unclosed-tag', 'Foo<tag>bar' );
		assert.htmlEqual(
			formatParse( 'jquerymsg-unclosed-tag' ),
			'Foo&lt;tag&gt;bar',
			'Nonsupported unclosed tags are escaped'
		);

		mw.messages.set( 'jquerymsg-self-closing-tag', 'Foo<tag/>bar' );
		assert.htmlEqual(
			formatParse( 'jquerymsg-self-closing-tag' ),
			'Foo&lt;tag/&gt;bar',
			'Self-closing tags don\'t cause a parse error'
		);
	} );

	QUnit.test( 'Behavior in case of invalid wikitext', 3, function ( assert ) {
		mw.messages.set( 'invalid-wikitext', '<b>{{FAIL}}</b>' );

		this.suppressWarnings();
		var logSpy = this.sandbox.spy( mw.log, 'warn' );

		assert.equal(
			formatParse( 'invalid-wikitext' ),
			'&lt;b&gt;{{FAIL}}&lt;/b&gt;',
			'Invalid wikitext: \'parse\' format'
		);

		assert.equal(
			formatText( 'invalid-wikitext' ),
			'<b>{{FAIL}}</b>',
			'Invalid wikitext: \'text\' format'
		);

		assert.equal( logSpy.callCount, 2, 'mw.log.warn calls' );
	} );

}( mediaWiki, jQuery ) );
