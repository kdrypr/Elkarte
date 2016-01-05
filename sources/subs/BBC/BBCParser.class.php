<?php

/**
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * This software is a derived product, based on:
 *
 * Simple Machines Forum (SMF)
 * copyright:	2011 Simple Machines (http://www.simplemachines.org)
 * license:		BSD, See included LICENSE.TXT for terms and conditions.
 *
 * @version 1.1 dev
 *
 */

namespace BBC;

class BBCParser
{
	const MAX_PERMUTE_ITERATIONS = 5040;

	protected $message;
	protected $bbc;
	protected $bbc_codes;
	protected $item_codes;
	protected $tags;
	protected $pos;
	protected $pos1;
	protected $pos2;
	protected $pos3;
	protected $last_pos;
	protected $do_smileys = true;
	protected $open_tags = array();
	// This is the actual tag that's open
	protected $inside_tag;

	protected $autolinker;
	protected $possible_html;
	protected $html_parser;

	protected $can_cache = true;
	protected $num_footnotes = 0;
	protected $smiley_marker = "\r";

	/**
	 * BBCParser constructor.
	 *
	 * @param \BBC\Codes $bbc
	 * @param \BBC\Autolink|null $autolinker
	 * @param \BBC\HtmlParser|null $html_parser
	 */
	public function __construct(Codes $bbc, Autolink $autolinker = null, HtmlParser $html_parser = null)
	{
		$this->bbc = $bbc;

		$this->bbc_codes = $this->bbc->getForParsing();
		$this->item_codes = $this->bbc->getItemCodes();

		$this->autolinker = $autolinker;
		$this->loadAutolink();

		$this->html_parser = $html_parser;
	}

	/**
	 * Reset the parser's properties for a new message
	 */
	public function resetParser()
	{
		$this->pos = -1;
		$this->pos1 = null;
		$this->pos2 = null;
		$this->last_pos = null;
		$this->open_tags = array();
		$this->inside_tag = null;
		$this->lastAutoPos = 0;
		$this->can_cache = true;
		$this->num_footnotes = 0;
	}

	/**
	 * Parse the BBC in a string/message
	 *
	 * @param string $message
	 *
	 * @return string
	 */
	public function parse($message)
	{
		call_integration_hook('integrate_pre_bbc_parser', array(&$message, $this->bbc));

		$this->message = $message;

		// Don't waste cycles
		if ($this->message === '')
		{
			return '';
		}

		// Clean up any cut/paste issues we may have
		$this->message = sanitizeMSCutPaste($this->message);

		// @todo remove from here and make the caller figure it out
		if (!$this->parsingEnabled())
		{
			return $this->message;
		}

		$this->resetParser();

		// @todo change this to <br> (it will break tests)
		$this->message = str_replace("\n", '<br />', $this->message);

		// Check if the message might have a link or email to save a bunch of parsing in autolink()
		$this->autolinker->setPossibleAutolink($this->message);

		$this->possible_html = !empty($GLOBALS['modSettings']['enablePostHTML']) && strpos($message, '&lt;') !== false;

		// Don't load the HTML Parser unless we have to
		if ($this->possible_html && $this->html_parser === null)
		{
			$this->loadHtmlParser();
		}

		// This handles pretty much all of the parsing. It is a separate method so it is easier to override and profile.
		$this->parse_loop();

		// Close any remaining tags.
		while ($tag = $this->closeOpenedTag())
		{
			$this->message .= $this->noSmileys($tag[Codes::ATTR_AFTER]);
		}

		if (isset($this->message[0]) && $this->message[0] === ' ')
		{
			$this->message = substr_replace($this->message, '&nbsp;', 0, 1);
			//$this->message = '&nbsp;' . substr($this->message, 1);
		}

		// Cleanup whitespace.
		$this->message = str_replace(array('  ', '<br /> ', '&#13;'), array('&nbsp; ', '<br />&nbsp;', "\n"), $this->message);

		// Finish footnotes if we have any.
		if ($this->num_footnotes > 0)
		{
			$this->handleFootnotes();
		}

		// Allow addons access to what the parser created
		$message = $this->message;
		call_integration_hook('integrate_post_bbc_parser', array(&$message));
		$this->message = $message;

		return $this->message;
	}

	protected function parse_loop()
	{
		while ($this->pos !== false)
		{
			$this->last_pos = isset($this->last_pos) ? max($this->pos, $this->last_pos) : $this->pos;
			$this->pos = strpos($this->message, '[', $this->pos + 1);

			// Failsafe.
			if ($this->pos === false || $this->last_pos > $this->pos)
			{
				$this->pos = strlen($this->message) + 1;
			}

			// Can't have a one letter smiley, URL, or email! (sorry.)
			if ($this->last_pos < $this->pos - 1)
			{
				$this->betweenTags();
			}

			// Are we there yet?  Are we there yet?
			if ($this->pos >= strlen($this->message) - 1)
			{
				return;
			}

			$next_char = strtolower($this->message[$this->pos + 1]);

			// Possibly a closer?
			if ($next_char === '/')
			{
				if ($this->hasOpenTags())
				{
					$this->handleOpenTags();
				}

				// We don't allow / to be used for anything but the closing character, so this can't be a tag
				continue;
			}

			// No tags for this character, so just keep going (fastest possible course.)
			if (!isset($this->bbc_codes[$next_char]))
			{
				continue;
			}

			$this->inside_tag = !$this->hasOpenTags() ? null : $this->getLastOpenedTag();

			if ($this->isItemCode($next_char) && isset($this->message[$this->pos + 2]) && $this->message[$this->pos + 2] === ']' && !$this->bbc->isDisabled('list') && !$this->bbc->isDisabled('li'))
			{
				// Itemcodes cannot be 0 and must be preceeded by a semi-colon, space, tab, new line, or greater than sign
				if (!($this->message[$this->pos + 1] === '0' && !in_array($this->message[$this->pos - 1], array(';', ' ', "\t", "\n", '>'))))
				{
					// Item codes are complicated buggers... they are implicit [li]s and can make [list]s!
					$this->handleItemCode();
				}

				// No matter what, we have to continue here.
				continue;
			}
			else
			{
				$tag = $this->findTag($this->bbc_codes[$next_char]);
			}

			// Implicitly close lists and tables if something other than what's required is in them. This is needed for itemcode.
			if ($tag === null && $this->inside_tag !== null && !empty($this->inside_tag[Codes::ATTR_REQUIRE_CHILDREN]))
			{
				$this->closeOpenedTag();
				$tmp = $this->noSmileys($this->inside_tag[Codes::ATTR_AFTER]);
				$this->message = substr_replace($this->message, $tmp, $this->pos, 0);
				$this->pos += strlen($tmp) - 1;
			}

			// No tag?  Keep looking, then.  Silly people using brackets without actual tags.
			if ($tag === null)
			{
				continue;
			}

			// Propagate the list to the child (so wrapping the disallowed tag won't work either.)
			if (isset($this->inside_tag[Codes::ATTR_DISALLOW_CHILDREN]))
			{
				$tag[Codes::ATTR_DISALLOW_CHILDREN] = isset($tag[Codes::ATTR_DISALLOW_CHILDREN]) ? $tag[Codes::ATTR_DISALLOW_CHILDREN] + $this->inside_tag[Codes::ATTR_DISALLOW_CHILDREN] : $this->inside_tag[Codes::ATTR_DISALLOW_CHILDREN];
			}

			// Is this tag disabled?
			if ($this->bbc->isDisabled($tag[Codes::ATTR_TAG]))
			{
				$this->handleDisabled($tag);
			}

			// The only special case is 'html', which doesn't need to close things.
			if ($tag[Codes::ATTR_BLOCK_LEVEL] && $tag[Codes::ATTR_TAG] !== 'html' && !$this->inside_tag[Codes::ATTR_BLOCK_LEVEL])
			{
				$this->closeNonBlockLevel();
			}

			// This is the part where we actually handle the tags. I know, crazy how long it took.
			if ($this->handleTag($tag))
			{
				continue;
			}

			// If this is block level, eat any breaks after it.
			if ($tag[Codes::ATTR_BLOCK_LEVEL] && isset($this->message[$this->pos + 1]) && substr_compare($this->message, '<br />', $this->pos + 1, 6) === 0)
			{
				$this->message = substr_replace($this->message, '', $this->pos + 1, 6);
			}

			// Are we trimming outside this tag?
			if (!empty($tag[Codes::ATTR_TRIM]) && $tag[Codes::ATTR_TRIM] !== Codes::TRIM_OUTSIDE)
			{
				$this->trimWhiteSpace($this->message, $this->pos + 1);
			}
		}
	}

	protected function handleOpenTags()
	{
		// Next closing bracket after the first character
		$this->pos2 = strpos($this->message, ']', $this->pos + 1);

		// Playing games? string = [/]
		if ($this->pos2 === $this->pos + 2)
		{
			return;
		}

		// Get everything between [/ and ]
		$look_for = strtolower(substr($this->message, $this->pos + 2, $this->pos2 - $this->pos - 2));
		$to_close = array();
		$block_level = null;

		do
		{
			// Get the last opened tag
			$tag = $this->closeOpenedTag();

			// No open tags
			if (!$tag)
			{
				break;
			}

			if ($tag[Codes::ATTR_BLOCK_LEVEL])
			{
				// Only find out if we need to.
				if ($block_level === false)
				{
					$this->addOpenTag($tag);
					break;
				}

				// The idea is, if we are LOOKING for a block level tag, we can close them on the way.
				if (isset($look_for[1]) && isset($this->bbc_codes[$look_for[0]]))
				{
					foreach ($this->bbc_codes[$look_for[0]] as $temp)
					{
						if ($temp[Codes::ATTR_TAG] === $look_for)
						{
							$block_level = $temp[Codes::ATTR_BLOCK_LEVEL];
							break;
						}
					}
				}

				if ($block_level !== true)
				{
					$block_level = false;
					$this->addOpenTag($tag);
					break;
				}
			}

			$to_close[] = $tag;
		} while ($tag[Codes::ATTR_TAG] !== $look_for);

		// Did we just eat through everything and not find it?
		if (!$this->hasOpenTags() && (empty($tag) || $tag[Codes::ATTR_TAG] !== $look_for))
		{
			$this->open_tags = $to_close;
			return;
		}
		elseif (!empty($to_close) && $tag[Codes::ATTR_TAG] !== $look_for)
		{
			if ($block_level === null && isset($look_for[0], $this->bbc_codes[$look_for[0]]))
			{
				foreach ($this->bbc_codes[$look_for[0]] as $temp)
				{
					if ($temp[Codes::ATTR_TAG] === $look_for)
					{
						$block_level = !empty($temp[Codes::ATTR_BLOCK_LEVEL]);
						break;
					}
				}
			}

			// We're not looking for a block level tag (or maybe even a tag that exists...)
			if (!$block_level)
			{
				foreach ($to_close as $tag)
				{
					$this->addOpenTag($tag);
				}

				return;
			}
		}

		foreach ($to_close as $tag)
		{
			$tmp = $this->noSmileys($tag[Codes::ATTR_AFTER]);
			$this->message = substr_replace($this->message, $tmp, $this->pos, $this->pos2 + 1 - $this->pos);
			$this->pos += strlen($tmp);
			$this->pos2 = $this->pos - 1;

			// See the comment at the end of the big loop - just eating whitespace ;).
			if ($tag[Codes::ATTR_BLOCK_LEVEL] && isset($this->message[$this->pos]) && substr_compare($this->message, '<br />', $this->pos, 6) === 0)
			{
				$this->message = substr_replace($this->message, '', $this->pos, 6);
			}

			// Trim inside whitespace
			if (!empty($tag[Codes::ATTR_TRIM]) && $tag[Codes::ATTR_TRIM] !== Codes::TRIM_INSIDE)
			{
				$this->trimWhiteSpace($this->message, $this->pos + 1);
			}
		}

		if (!empty($to_close))
		{
			$this->pos--;
		}
	}

	/**
	 * Turn smiley parsing on/off
	 * @param bool $toggle
	 * @return \BBC\Parser
	 */
	public function doSmileys($toggle)
	{
		$this->do_smileys = (bool) $toggle;
		return $this;
	}

	/**
	 * Check if parsing is enabled
	 *
	 * @return bool
	 */
	public function parsingEnabled()
	{
		return !empty($GLOBALS['modSettings']['enableBBC']);
	}

	public function loadHtmlParser()
	{
		$parser = new HtmlParser;
		call_integration_hook('integrate_bbc_load_html_parser', array(&$parser));
		$this->html_parser = $parser;
	}

	/**
	 * Parse the HTML in a string
	 *
	 * @param string &$data
	 */
	protected function parseHTML(&$data)
	{
		$this->html_parser->parse($data);
	}

	/**
	 * Parse URIs and email addresses in a string to url and email BBC tags to be parsed by the BBC parser
	 *
	 * @param string &$data
	 */
	protected function autoLink(&$data)
	{
		if ($data === '' || $data === $this->smiley_marker || !$this->autolinker->hasPossible())
		{
			return;
		}

		// Are we inside tags that should be auto linked?
		if ($this->hasOpenTags())
		{
			foreach ($this->getOpenedTags() as $open_tag)
			{
				if (!$open_tag[Codes::ATTR_AUTOLINK])
				{
					return;
				}
			}
		}

		$this->autolinker->parse($data);
	}

	/**
	 * Load the autolink regular expression to be used in autoLink()
	 */
	protected function loadAutolink()
	{
		if ($this->autolinker === null)
		{
			$this->autolinker = new Autolink($this->bbc);
		}
	}

	/**
	 * Find if the current character is the start of a tag and get it
	 *
	 * @param array $possible_codes
	 *
	 * @return null|array the tag that was found or null if no tag found
	 */
	protected function findTag(array $possible_codes)
	{
		$tag = null;
		$last_check = null;

		foreach ($possible_codes as $possible)
		{
			// Skip tags that didn't match the next X characters
			if ($possible[Codes::ATTR_TAG] === $last_check)
			{
				continue;
			}

			// The character after the possible tag or nothing
			$next_c = isset($this->message[$this->pos + 1 + $possible[Codes::ATTR_LENGTH]]) ? $this->message[$this->pos + 1 + $possible[Codes::ATTR_LENGTH]] : '';

			// This only happens if the tag is the last character of the string
			if ($next_c === '')
			{
				break;
			}

			// The next character must be one of these or it's not a tag
			if ($next_c !== ' ' && $next_c !== ']' && $next_c !== '=' && $next_c !== '/')
			{
				$last_check = $possible[Codes::ATTR_TAG];
				continue;
			}

			// Not a match?
			if (substr_compare($this->message, $possible[Codes::ATTR_TAG], $this->pos + 1, $possible[Codes::ATTR_LENGTH], true) !== 0)
			{
				$last_check = $possible[Codes::ATTR_TAG];
				continue;
			}

			$tag = $this->checkCodeAttributes($next_c, $possible, $tag);
			if ($tag === null)
			{
				continue;
			}

			// Quotes can have alternate styling, we do this php-side due to all the permutations of quotes.
			if ($tag[Codes::ATTR_TAG] === 'quote')
			{
				$this->alternateQuoteStyle($tag);
			}

			break;
		}

		// If there is a code that says you can't cache, the message can't be cached
		if ($tag !== null && $this->can_cache !== false)
		{
			$this->can_cache = empty($tag[Codes::ATTR_NO_CACHE]);
		}

		if ($tag[Codes::ATTR_TAG] === 'footnote')
		{
			$this->num_footnotes++;
		}

		return $tag;
	}

	/**
	 * @param array $tag
	 */
	protected function alternateQuoteStyle(array &$tag)
	{
		// Start with standard
		$quote_alt = false;
		foreach ($this->open_tags as $open_quote)
		{
			// Every parent quote this quote has flips the styling
			if ($open_quote[Codes::ATTR_TAG] === 'quote')
			{
				$quote_alt = !$quote_alt;
			}
		}
		// Add a class to the quote to style alternating blockquotes
		// @todo - Frankly it makes little sense to allow alternate blockquote
		// styling without also catering for alternate quoteheader styling.
		// I do remember coding that some time back, but it seems to have gotten
		// lost somewhere in the Elk processes.
		// Come to think of it, it may be better to append a second class rather
		// than alter the standard one.
		//  - Example: class="bbc_quote" and class="bbc_quote alt_quote".
		// This would mean simpler CSS for themes (like default) which do not use the alternate styling,
		// but would still allow it for themes that want it.
		$tag[Codes::ATTR_BEFORE] = str_replace('<blockquote>', '<blockquote class="bbc_' . ($quote_alt ? 'alternate' : 'standard') . '_quote">', $tag[Codes::ATTR_BEFORE]);
	}

	/**
	 * @param $next_c
	 * @param array $possible
	 * @return array|void
	 */
	protected function checkCodeAttributes($next_c, array $possible)
	{
		// Do we want parameters?
		if (!empty($possible[Codes::ATTR_PARAM]))
		{
			if ($next_c !== ' ')
			{
				return;
			}
		}
		// parsed_content demands an immediate ] without parameters!
		elseif ($possible[Codes::ATTR_TYPE] === Codes::TYPE_PARSED_CONTENT)
		{
			if ($next_c !== ']')
			{
				return;
			}
		}
		else
		{
			// Do we need an equal sign?
			if ($next_c !== '=' && in_array($possible[Codes::ATTR_TYPE], array(Codes::TYPE_UNPARSED_EQUALS, Codes::TYPE_UNPARSED_COMMAS, Codes::TYPE_UNPARSED_COMMAS_CONTENT, Codes::TYPE_UNPARSED_EQUALS_CONTENT, Codes::TYPE_PARSED_EQUALS)))
			{
				return;
			}

			if ($next_c !== ']')
			{
				// An immediate ]?
				if ($possible[Codes::ATTR_TYPE] === Codes::TYPE_UNPARSED_CONTENT)
				{
					return;
				}
				// Maybe we just want a /...
				elseif ($possible[Codes::ATTR_TYPE] === Codes::TYPE_CLOSED && substr_compare($this->message, '/]', $this->pos + 1 + $possible[Codes::ATTR_LENGTH], 2) !== 0 && substr_compare($this->message, ' /]', $this->pos + 1 + $possible[Codes::ATTR_LENGTH], 3) !== 0)
				{
					return;
				}
			}
		}


		// Check allowed tree?
		if (isset($possible[Codes::ATTR_REQUIRE_PARENTS]) && ($this->inside_tag === null || !isset($possible[Codes::ATTR_REQUIRE_PARENTS][$this->inside_tag[Codes::ATTR_TAG]])))
		{
			return;
		}

		if ($this->inside_tag !== null)
		{
			if (isset($this->inside_tag[Codes::ATTR_REQUIRE_CHILDREN]) && !isset($this->inside_tag[Codes::ATTR_REQUIRE_CHILDREN][$possible[Codes::ATTR_TAG]]))
			{
				return;
			}

			// If this is in the list of disallowed child tags, don't parse it.
			if (isset($this->inside_tag[Codes::ATTR_DISALLOW_CHILDREN]) && isset($this->inside_tag[Codes::ATTR_DISALLOW_CHILDREN][$possible[Codes::ATTR_TAG]]))
			{
				return;
			}

			// Not allowed in this parent, replace the tags or show it like regular text
			if (isset($possible[Codes::ATTR_DISALLOW_PARENTS]) && isset($possible[Codes::ATTR_DISALLOW_PARENTS][$this->inside_tag[Codes::ATTR_TAG]]))
			{
				if (!isset($possible[Codes::ATTR_DISALLOW_BEFORE], $possible[Codes::ATTR_DISALLOW_AFTER]))
				{
					return;
				}

				$possible[Codes::ATTR_BEFORE] = isset($possible[Codes::ATTR_DISALLOW_BEFORE]) ? $possible[Codes::ATTR_DISALLOW_BEFORE] : $possible[Codes::ATTR_BEFORE];
				$possible[Codes::ATTR_AFTER] = isset($possible[Codes::ATTR_DISALLOW_AFTER]) ? $possible[Codes::ATTR_DISALLOW_AFTER] : $possible[Codes::ATTR_AFTER];
			}
		}

		if (isset($possible[Codes::ATTR_TEST]) && $this->handleTest($possible))
		{
			return;
		}

		// +1 for [, then the length of the tag, then a space
		$this->pos1 = $this->pos + 1 + $possible[Codes::ATTR_LENGTH] + 1;

		// This is long, but it makes things much easier and cleaner.
		if (!empty($possible[Codes::ATTR_PARAM]))
		{
			$match = $this->matchParameters($possible, $matches);

			// Didn't match our parameter list, try the next possible.
			if (!$match)
			{
				return;
			}

			return $this->setupTagParameters($possible, $matches);
		}

		return $possible;
	}

	protected function handleTest(array $possible)
	{
		return preg_match('~^' . $possible[Codes::ATTR_TEST] . '~', substr($this->message, $this->pos + 2 + $possible[Codes::ATTR_LENGTH], strpos($this->message, ']', $this->pos) - ($this->pos + 2 + $possible[Codes::ATTR_LENGTH]))) === 0;
	}

	protected function handleItemCode()
	{
		$tag = $this->item_codes[$this->message[$this->pos + 1]];

		// First let's set up the tree: it needs to be in a list, or after an li.
		if ($this->inside_tag === null || ($this->inside_tag[Codes::ATTR_TAG] !== 'list' && $this->inside_tag[Codes::ATTR_TAG] !== 'li'))
		{
			$this->addOpenTag(array(
				Codes::ATTR_TAG => 'list',
				Codes::ATTR_TYPE => Codes::TYPE_PARSED_CONTENT,
				Codes::ATTR_AFTER => '</ul>',
				Codes::ATTR_BLOCK_LEVEL => true,
				Codes::ATTR_REQUIRE_CHILDREN => array('li' => 'li'),
				Codes::ATTR_DISALLOW_CHILDREN => isset($this->inside_tag[Codes::ATTR_DISALLOW_CHILDREN]) ? $this->inside_tag[Codes::ATTR_DISALLOW_CHILDREN] : null,
				Codes::ATTR_LENGTH => 4,
				Codes::ATTR_AUTOLINK => true,
			));
			$code = '<ul' . ($tag === '' ? '' : ' style="list-style-type: ' . $tag . '"') . ' class="bbc_list">';
		}
		// We're in a list item already: another itemcode?  Close it first.
		elseif ($this->inside_tag[Codes::ATTR_TAG] === 'li')
		{
			$this->closeOpenedTag();
			$code = '</li>';
		}
		else
		{
			$code = '';
		}

		// Now we open a new tag.
		$this->addOpenTag(array(
			Codes::ATTR_TAG => 'li',
			Codes::ATTR_TYPE => Codes::TYPE_PARSED_CONTENT,
			Codes::ATTR_AFTER => '</li>',
			Codes::ATTR_TRIM => Codes::TRIM_OUTSIDE,
			Codes::ATTR_BLOCK_LEVEL => true,
			Codes::ATTR_DISALLOW_CHILDREN => isset($this->inside_tag[Codes::ATTR_DISALLOW_CHILDREN]) ? $this->inside_tag[Codes::ATTR_DISALLOW_CHILDREN] : null,
			Codes::ATTR_AUTOLINK => true,
			Codes::ATTR_LENGTH => 2,
		));

		// First, open the tag...
		$code .= '<li>';

		$tmp = $this->noSmileys($code);
		$this->message = substr_replace($this->message, $tmp, $this->pos, 3);
		$this->pos += strlen($tmp) - 1;

		// Next, find the next break (if any.)  If there's more itemcode after it, keep it going - otherwise close!
		$this->pos2 = strpos($this->message, '<br />', $this->pos);
		$this->pos3 = strpos($this->message, '[/', $this->pos);

		$num_open_tags = count($this->open_tags);
		if ($this->pos2 !== false && ($this->pos3 === false || $this->pos2 <= $this->pos3))
		{
			// Can't use offset because of the ^
			preg_match('~^(<br />|&nbsp;|\s|\[)+~', substr($this->message, $this->pos2 + 6), $matches);
			//preg_match('~(<br />|&nbsp;|\s|\[)+~', $this->message, $matches, 0, $this->pos2 + 6);

			// Keep the list open if the next character after the break is a [. Otherwise, close it.
			$replacement = !empty($matches[0]) && substr_compare($matches[0], '[', -1, 1) === 0 ? '[/li]' : '[/li][/list]';

			$this->message = substr_replace($this->message, $replacement, $this->pos2, 0);
			$this->open_tags[$num_open_tags - 2][Codes::ATTR_AFTER] = '</ul>';
		}
		// Tell the [list] that it needs to close specially.
		else
		{
			// Move the li over, because we're not sure what we'll hit.
			$this->open_tags[$num_open_tags - 1][Codes::ATTR_AFTER] = '';
			$this->open_tags[$num_open_tags - 2][Codes::ATTR_AFTER] = '</li></ul>';
		}
	}

	/**
	 * Handle codes that are of the parsed context type
	 * @param array $tag
	 *
	 * @return bool
	 */
	protected function handleTypeParsedContext(array $tag)
	{
		// @todo Check for end tag first, so people can say "I like that [i] tag"?
		$this->addOpenTag($tag);
		$tmp = $this->noSmileys($tag[Codes::ATTR_BEFORE]);
		$this->message = substr_replace($this->message, $tmp, $this->pos, $this->pos1 - $this->pos);
		$this->pos += strlen($tmp) - 1;

		return false;
	}

	/**
	 * Handle codes that are of the unparsed context type
	 * @param array $tag
	 *
	 * @return bool
	 */
	protected function handleTypeUnparsedContext(array $tag)
	{
		// Find the next closer
		$this->pos2 = stripos($this->message, '[/' . $tag[Codes::ATTR_TAG] . ']', $this->pos1);

		// No closer
		if ($this->pos2 === false)
		{
			return true;
		}

		$data = substr($this->message, $this->pos1, $this->pos2 - $this->pos1);

		if (!empty($tag[Codes::ATTR_BLOCK_LEVEL]) && isset($data[0]) && substr_compare($data, '<br />', 0, 6) === 0)
		{
			$data = substr($data, 6);
		}

		if (isset($tag[Codes::ATTR_VALIDATE]))
		{
			//$tag[Codes::ATTR_VALIDATE]($tag, $data, $this->bbc->getDisabled());
			$this->filterData($tag, $data);
		}

		$code = strtr($tag[Codes::ATTR_CONTENT], array('$1' => $data));
		$tmp = $this->noSmileys($code);
		$this->message = substr_replace($this->message, $tmp, $this->pos, $this->pos2 + 3 + $tag[Codes::ATTR_LENGTH] - $this->pos);
		$this->pos += strlen($tmp) - 1;
		$this->last_pos = $this->pos + 1;

		return false;
	}

	/**
	 * Handle codes that are of the unparsed equals context type
	 * @param array $tag
	 *
	 * @return bool
	 */
	protected function handleUnparsedEqualsContext(array $tag)
	{
		// The value may be quoted for some tags - check.
		if (isset($tag[Codes::ATTR_QUOTED]))
		{
			$quoted = substr_compare($this->message, '&quot;', $this->pos1, 6) === 0;
			if ($tag[Codes::ATTR_QUOTED] !== Codes::OPTIONAL && !$quoted)
			{
				return true;
			}

			if ($quoted)
			{
				$this->pos1 += 6;
			}
		}
		else
		{
			$quoted = false;
		}

		$this->pos2 = strpos($this->message, $quoted === false ? ']' : '&quot;]', $this->pos1);
		if ($this->pos2 === false)
		{
			return true;
		}

		$this->pos3 = stripos($this->message, '[/' . $tag[Codes::ATTR_TAG] . ']', $this->pos2);
		if ($this->pos3 === false)
		{
			return true;
		}

		$data = array(
			substr($this->message, $this->pos2 + ($quoted === false ? 1 : 7), $this->pos3 - ($this->pos2 + ($quoted === false ? 1 : 7))),
			substr($this->message, $this->pos1, $this->pos2 - $this->pos1)
		);

		if (!empty($tag[Codes::ATTR_BLOCK_LEVEL]) && substr_compare($data[0], '<br />', 0, 6) === 0)
		{
			$data[0] = substr($data[0], 6);
		}

		// Validation for my parking, please!
		if (isset($tag[Codes::ATTR_VALIDATE]))
		{
			//$tag[Codes::ATTR_VALIDATE]($tag, $data, $this->bbc->getDisabled());
			$this->filterData($tag, $data);
		}

		$code = strtr($tag[Codes::ATTR_CONTENT], array('$1' => $data[0], '$2' => $data[1]));
		$tmp = $this->noSmileys($code);
		$this->message = substr_replace($this->message, $tmp, $this->pos, $this->pos3 + 3 + $tag[Codes::ATTR_LENGTH] - $this->pos);
		$this->pos += strlen($tmp) - 1;

		return false;
	}

	/**
	 * Handle codes that are of the closed type
	 * @param array $tag
	 *
	 * @return bool
	 */
	protected function handleTypeClosed(array $tag)
	{
		$this->pos2 = strpos($this->message, ']', $this->pos);
		$tmp = $this->noSmileys($tag[Codes::ATTR_CONTENT]);
		$this->message = substr_replace($this->message, $tmp, $this->pos, $this->pos2 + 1 - $this->pos);
		$this->pos += strlen($tmp) - 1;

		return false;
	}

	/**
	 * Handle codes that are of the unparsed commas context type
	 * @param array $tag
	 *
	 * @return bool
	 */
	protected function handleUnparsedCommasContext(array $tag)
	{
		$this->pos2 = strpos($this->message, ']', $this->pos1);
		if ($this->pos2 === false)
		{
			return true;
		}

		$this->pos3 = stripos($this->message, '[/' . $tag[Codes::ATTR_TAG] . ']', $this->pos2);
		if ($this->pos3 === false)
		{
			return true;
		}

		// We want $1 to be the content, and the rest to be csv.
		$data = explode(',', ',' . substr($this->message, $this->pos1, $this->pos2 - $this->pos1));
		$data[0] = substr($this->message, $this->pos2 + 1, $this->pos3 - $this->pos2 - 1);

		if (isset($tag[Codes::ATTR_VALIDATE]))
		{
			//$tag[Codes::ATTR_VALIDATE]($tag, $data, $this->bbc->getDisabled());
			$this->filterData($tag, $data);
		}

		$code = $tag[Codes::ATTR_CONTENT];
		foreach ($data as $k => $d)
		{
			$code = strtr($code, array('$' . ($k + 1) => trim($d)));
		}

		$tmp = $this->noSmileys($code);
		$this->message = substr_replace($this->message, $tmp, $this->pos, $this->pos3 + 3 + $tag[Codes::ATTR_LENGTH] - $this->pos);
		$this->pos += strlen($tmp) - 1;

		return false;
	}

	/**
	 * Handle codes that are of the unparsed commas type
	 * @param array $tag
	 *
	 * @return bool
	 */
	protected function handleUnparsedCommas(array $tag)
	{
		$this->pos2 = strpos($this->message, ']', $this->pos1);
		if ($this->pos2 === false)
		{
			return true;
		}

		$data = explode(',', substr($this->message, $this->pos1, $this->pos2 - $this->pos1));

		if (isset($tag[Codes::ATTR_VALIDATE]))
		{
			//$tag[Codes::ATTR_VALIDATE]($tag, $data, $this->bbc->getDisabled());
			$this->filterData($tag, $data);
		}

		// Fix after, for disabled code mainly.
		foreach ($data as $k => $d)
		{
			$tag[Codes::ATTR_AFTER] = strtr($tag[Codes::ATTR_AFTER], array('$' . ($k + 1) => trim($d)));
		}

		$this->addOpenTag($tag);

		// Replace them out, $1, $2, $3, $4, etc.
		$code = $tag[Codes::ATTR_BEFORE];
		foreach ($data as $k => $d)
		{
			$code = strtr($code, array('$' . ($k + 1) => trim($d)));
		}

		$tmp = $this->noSmileys($code);
		$this->message = substr_replace($this->message, $tmp, $this->pos, $this->pos2 + 1 - $this->pos);
		$this->pos += strlen($tmp) - 1;

		return false;
	}

	/**
	 * Handle codes that are of the equals type
	 * @param array $tag
	 *
	 * @return bool
	 */
	protected function handleEquals(array $tag)
	{
		// The value may be quoted for some tags - check.
		if (isset($tag[Codes::ATTR_QUOTED]))
		{
			$quoted = substr_compare($this->message, '&quot;', $this->pos1, 6) === 0;
			if ($tag[Codes::ATTR_QUOTED] !== Codes::OPTIONAL && !$quoted)
			{
				return true;
			}

			if ($quoted)
			{
				$this->pos1 += 6;
			}
		}
		else
		{
			$quoted = false;
		}

		$this->pos2 = strpos($this->message, $quoted === false ? ']' : '&quot;]', $this->pos1);
		if ($this->pos2 === false)
		{
			return true;
		}

		$data = substr($this->message, $this->pos1, $this->pos2 - $this->pos1);

		// Validation for my parking, please!
		if (isset($tag[Codes::ATTR_VALIDATE]))
		{
			//$tag[Codes::ATTR_VALIDATE]($tag, $data, $this->bbc->getDisabled());
			$this->filterData($tag, $data);
		}

		// For parsed content, we must recurse to avoid security problems.
		if ($tag[Codes::ATTR_TYPE] === Codes::TYPE_PARSED_EQUALS)
		{
			$this->recursiveParser($data, $tag);
		}

		$tag[Codes::ATTR_AFTER] = strtr($tag[Codes::ATTR_AFTER], array('$1' => $data));

		$this->addOpenTag($tag);

		$code = strtr($tag[Codes::ATTR_BEFORE], array('$1' => $data));
		$tmp = $this->noSmileys($code);
		$this->message = substr_replace($this->message, $tmp, $this->pos, $this->pos2 + ($quoted === false ? 1 : 7) - $this->pos);
		$this->pos += strlen($tmp) - 1;

		return false;
	}

	/**
	 * Handles a tag by its type. Offloads the actual handling to handle*() method
	 * @param array $tag
	 *
	 * @return bool true if there was something wrong and the parser should advance
	 */
	protected function handleTag(array $tag)
	{
		switch ($tag[Codes::ATTR_TYPE])
		{
			case Codes::TYPE_PARSED_CONTENT:
				return $this->handleTypeParsedContext($tag);

			// Don't parse the content, just skip it.
			case Codes::TYPE_UNPARSED_CONTENT:
				return $this->handleTypeUnparsedContext($tag);

			// Don't parse the content, just skip it.
			case Codes::TYPE_UNPARSED_EQUALS_CONTENT:
				return $this->handleUnparsedEqualsContext($tag);

			// A closed tag, with no content or value.
			case Codes::TYPE_CLOSED:
				return $this->handleTypeClosed($tag);

			// This one is sorta ugly... :/
			case Codes::TYPE_UNPARSED_COMMAS_CONTENT:
				return $this->handleUnparsedCommasContext($tag);

			// This has parsed content, and a csv value which is unparsed.
			case Codes::TYPE_UNPARSED_COMMAS:
				return $this->handleUnparsedCommas($tag);

			// A tag set to a value, parsed or not.
			case Codes::TYPE_PARSED_EQUALS:
			case Codes::TYPE_UNPARSED_EQUALS:
				return $this->handleEquals($tag);
		}

		return false;
	}

	// @todo I don't know what else to call this. It's the area that isn't a tag.
	protected function betweenTags()
	{
		// Make sure the $this->last_pos is not negative.
		$this->last_pos = max($this->last_pos, 0);

		// Pick a block of data to do some raw fixing on.
		$data = substr($this->message, $this->last_pos, $this->pos - $this->last_pos);

		// This happens when the pos is > last_pos and there is a trailing \n from one of the tags having "AFTER"
		// In micro-optimization tests, using substr() here doesn't prove to be slower. This is much easier to read so leave it.
		if ($data === $this->smiley_marker)
		{
			return;
		}

		// Take care of some HTML!
		if ($this->possible_html && strpos($data, '&lt;') !== false)
		{
			// @todo new \Parser\BBC\HTML;
			$this->parseHTML($data);
		}

		if (!empty($GLOBALS['modSettings']['autoLinkUrls']))
		{
			$this->autoLink($data);
		}

		// This cannot be moved earlier. It breaks tests
		$data = str_replace("\t", '&nbsp;&nbsp;&nbsp;', $data);

		// If it wasn't changed, no copying or other boring stuff has to happen!
		if (substr_compare($this->message, $data, $this->last_pos, $this->pos - $this->last_pos))
		{
			$this->message = substr_replace($this->message, $data, $this->last_pos, $this->pos - $this->last_pos);

			// Since we changed it, look again in case we added or removed a tag.  But we don't want to skip any.
			$old_pos = strlen($data) + $this->last_pos;
			$this->pos = strpos($this->message, '[', $this->last_pos);
			$this->pos = $this->pos === false ? $old_pos : min($this->pos, $old_pos);
		}
	}

	protected function handleFootnotes()
	{
		global $fn_num, $fn_content, $fn_count;
		static $fn_total;

		// @todo temporary until we have nesting
		$this->message = str_replace(array('[footnote]', '[/footnote]'), '', $this->message);

		$fn_num = 0;
		$fn_content = array();
		$fn_count = isset($fn_total) ? $fn_total : 0;

		// Replace our footnote text with a [1] link, save the text for use at the end of the message
		$this->message = preg_replace_callback('~(%fn%(.*?)%fn%)~is', array($this, 'footnoteCallback'), $this->message);
		$fn_total += $fn_num;

		// If we have footnotes, add them in at the end of the message
		if (!empty($fn_num))
		{
			$this->message .= '<div class="bbc_footnotes">' . implode('', $fn_content) . '</div>';
		}
	}

	/**
	 * @param array $matches
	 * @return string
	 */
	protected function footnoteCallback(array $matches)
	{
		global $fn_num, $fn_content, $fn_count;

		$fn_num++;
		$fn_content[] = '<div class="target" id="fn' . $fn_num . '_' . $fn_count . '"><sup>' . $fn_num . '&nbsp;</sup>' . $matches[2] . '<a class="footnote_return" href="#ref' . $fn_num . '_' . $fn_count . '">&crarr;</a></div>';

		return '<a class="target" href="#fn' . $fn_num . '_' . $fn_count . '" id="ref' . $fn_num . '_' . $fn_count . '">[' . $fn_num . ']</a>';
	}

	/**
	 * Parse a tag that is disabled
	 * @param array $tag
	 */
	protected function handleDisabled(array &$tag)
	{
		if (!isset($tag[Codes::ATTR_DISABLED_BEFORE]) && !isset($tag[Codes::ATTR_DISABLED_AFTER]) && !isset($tag[Codes::ATTR_DISABLED_CONTENT]))
		{
			$tag[Codes::ATTR_BEFORE] = !empty($tag[Codes::ATTR_BLOCK_LEVEL]) ? '<div>' : '';
			$tag[Codes::ATTR_AFTER] = !empty($tag[Codes::ATTR_BLOCK_LEVEL]) ? '</div>' : '';
			$tag[Codes::ATTR_CONTENT] = $tag[Codes::ATTR_TYPE] === Codes::TYPE_CLOSED ? '' : (!empty($tag[Codes::ATTR_BLOCK_LEVEL]) ? '<div>$1</div>' : '$1');
		}
		elseif (isset($tag[Codes::ATTR_DISABLED_BEFORE]) || isset($tag[Codes::ATTR_DISABLED_AFTER]))
		{
			$tag[Codes::ATTR_BEFORE] = isset($tag[Codes::ATTR_DISABLED_BEFORE]) ? $tag[Codes::ATTR_DISABLED_BEFORE] : (!empty($tag[Codes::ATTR_BLOCK_LEVEL]) ? '<div>' : '');
			$tag[Codes::ATTR_AFTER] = isset($tag[Codes::ATTR_DISABLED_AFTER]) ? $tag[Codes::ATTR_DISABLED_AFTER] : (!empty($tag[Codes::ATTR_BLOCK_LEVEL]) ? '</div>' : '');
		}
		else
		{
			$tag[Codes::ATTR_CONTENT] = $tag[Codes::ATTR_DISABLED_CONTENT];
		}
	}

	/**
	 * @param array &$possible
	 * @param array &$matches
	 * @return bool
	 */
	protected function matchParameters(array &$possible, &$matches)
	{
		if (!isset($possible['regex_cache']))
		{
			$possible['regex_cache'] = array();
			foreach ($possible[Codes::ATTR_PARAM] as $p => $info)
			{
				$quote = empty($info[Codes::PARAM_ATTR_QUOTED]) ? '' : '&quot;';

				$possible['regex_cache'][] = '(\s+' . $p . '=' . $quote . (isset($info[Codes::PARAM_ATTR_MATCH]) ? $info[Codes::PARAM_ATTR_MATCH] : '(.+?)') . $quote . ')' . (empty($info[Codes::PARAM_ATTR_OPTIONAL]) ? '' : '?');
			}
			$possible['regex_size'] = count($possible['regex_cache']) - 1;
			$possible['regex_keys'] = range(0, $possible['regex_size']);
		}

		// Okay, this may look ugly and it is, but it's not going to happen much and it is the best way
		// of allowing any order of parameters but still parsing them right.
		$message_stub = substr($this->message, $this->pos1 - 1);

		// If an addon adds many parameters we can exceed max_execution time, lets prevent that
		// 5040 = 7, 40,320 = 8, (N!) etc
		$max_iterations = self::MAX_PERMUTE_ITERATIONS;

		// Use the same range to start each time. Most BBC is in the order that it should be in when it starts.
		$keys = $possible['regex_keys'];

		// Step, one by one, through all possible permutations of the parameters until we have a match
		do
		{
			$match_preg = '~^';
			foreach ($keys as $key)
			{
				$match_preg .= $possible['regex_cache'][$key];
			}
			$match_preg .= '\]~i';

			// Check if this combination of parameters matches the user input
			$match = preg_match($match_preg, $message_stub, $matches) !== 0;
		} while (!$match && --$max_iterations && ($keys = pc_next_permutation($keys, $possible['regex_size'])));

		return $match;
	}

	/**
	 * Recursively call the parser with a new Codes object
	 * This allows to parse BBC in parameters like [quote author="[url]www.quotes.com[/url]"]Something famous.[/quote]
	 *
	 * @param string &$data
	 * @param array $tag
	 */
	protected function recursiveParser(&$data, array $tag)
	{
		// @todo if parsed tags allowed is empty, return?
		$bbc = clone $this->bbc;

		if (!empty($tag[Codes::ATTR_PARSED_TAGS_ALLOWED]))
		{
			$bbc->setParsedTags($tag[Codes::ATTR_PARSED_TAGS_ALLOWED]);
		}

		// Do not use $this->autolinker. For some reason it causes a recursive loop
		$autolinker = null;
		$html = null;
		call_integration_hook('integrate_recursive_bbc_parser', array(&$autolinker, &$html));

		$parser = new \BBC\Parser($bbc, $autolinker, $html);
		$data = $parser->enableSmileys(empty($tag[Codes::ATTR_PARSED_TAGS_ALLOWED]))->parse($data);
	}

	/**
	 * @return array
	 */
	public function getBBC()
	{
		return $this->bbc_codes;
	}

	/**
	 * Enable the parsing of smileys
	 * @param bool|true $enable
	 *
	 * @return $this
	 */
	public function enableSmileys($enable = true)
	{
		$this->do_smileys = (bool) $enable;
		return $this;
	}

	/**
	 * Open a tag
	 * @param array $tag
	 */
	protected function addOpenTag(array $tag)
	{
		$this->open_tags[] = $tag;
	}

	/**
	 * @param string|false $tag = false False closes the last open tag. Anything else finds that tag LIFO
	 *
	 * @return mixed
	 */
	protected function closeOpenedTag($tag = false)
	{
		if ($tag === false)
		{
			return array_pop($this->open_tags);
		}
		elseif (isset($this->open_tags[$tag]))
		{
			$return = $this->open_tags[$tag];
			unset($this->open_tags[$tag]);
			return $return;
		}
	}

	/**
	 * Check if there are any tags that are open
	 * @return bool
	 */
	protected function hasOpenTags()
	{
		return !empty($this->open_tags);
	}

	/**
	 * Get the last opened tag
	 * @return array
	 */
	protected function getLastOpenedTag()
	{
		return end($this->open_tags);
	}

	/**
	 * Get the currently opened tags
	 * @param bool|false $tags_only True if you want just the tag or false for the whole code
	 *
	 * @return array
	 */
	protected function getOpenedTags($tags_only = false)
	{
		if (!$tags_only)
		{
			return $this->open_tags;
		}

		$tags = array();
		foreach ($this->open_tags as $tag)
		{
			$tags[] = $tag[Codes::ATTR_TAG];
		}
		return $tags;
	}

	/**
	 * @param string &$message
	 * @param null|int $offset = null
	 */
	protected function trimWhiteSpace(&$message, $offset = null)
	{
		if (preg_match('~(<br />|&nbsp;|\s)*~', $this->message, $matches, null, $offset) !== 0 && isset($matches[0]) && $matches[0] !== '')
		{
			$this->message = substr_replace($this->message, '', $this->pos, strlen($matches[0]));
		}
	}

	/**
	 * @param array $possible
	 * @param array $matches
	 *
	 * @return array
	 */
	protected function setupTagParameters(array $possible, array $matches)
	{
		$params = array();
		for ($i = 1, $n = count($matches); $i < $n; $i += 2)
		{
			$key = strtok(ltrim($matches[$i]), '=');

			if (isset($possible[Codes::ATTR_PARAM][$key][Codes::PARAM_ATTR_VALUE]))
			{
				$params['{' . $key . '}'] = strtr($possible[Codes::ATTR_PARAM][$key][Codes::PARAM_ATTR_VALUE], array('$1' => $matches[$i + 1]));
			}
			elseif (isset($possible[Codes::ATTR_PARAM][$key][Codes::ATTR_VALIDATE]))
			{
				$params['{' . $key . '}'] = $possible[Codes::ATTR_PARAM][$key][Codes::ATTR_VALIDATE]($matches[$i + 1]);
			}
			else
			{
				$params['{' . $key . '}'] = $matches[$i + 1];
			}

			// Just to make sure: replace any $ or { so they can't interpolate wrongly.
			$params['{' . $key . '}'] = str_replace(array('$', '{'), array('&#036;', '&#123;'), $params['{' . $key . '}']);
		}

		foreach ($possible[Codes::ATTR_PARAM] as $p => $info)
		{
			if (!isset($params['{' . $p . '}']))
			{
				$params['{' . $p . '}'] = '';
			}
		}

		// We found our tag
		$tag = $possible;

		// Put the parameters into the string.
		if (isset($tag[Codes::ATTR_BEFORE]))
		{
			$tag[Codes::ATTR_BEFORE] = strtr($tag[Codes::ATTR_BEFORE], $params);
		}
		if (isset($tag[Codes::ATTR_AFTER]))
		{
			$tag[Codes::ATTR_AFTER] = strtr($tag[Codes::ATTR_AFTER], $params);
		}
		if (isset($tag[Codes::ATTR_CONTENT]))
		{
			$tag[Codes::ATTR_CONTENT] = strtr($tag[Codes::ATTR_CONTENT], $params);
		}

		$this->pos1 += strlen($matches[0]) - 1;

		return $tag;
	}

	/**
	 * Check if a tag (not a code) is open
	 * @param string $tag
	 *
	 * @return bool
	 */
	protected function isOpen($tag)
	{
		foreach ($this->open_tags as $open)
		{
			if ($open[Codes::ATTR_TAG] === $tag)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a character is an item code
	 * @param string $char
	 *
	 * @return bool
	 */
	protected function isItemCode($char)
	{
		return isset($this->item_codes[$char]);
	}

	/**
	 * Close any open codes that aren't block level.
	 * Used before opening a code that *is* block level
	 */
	protected function closeNonBlockLevel()
	{
		$n = count($this->open_tags) - 1;
		while (empty($this->open_tags[$n][Codes::ATTR_BLOCK_LEVEL]) && $n >= 0)
		{
			$n--;
		}

		// Close all the non block level tags so this tag isn't surrounded by them.
		for ($i = count($this->open_tags) - 1; $i > $n; $i--)
		{
			$tmp = $this->noSmileys($this->open_tags[$i][Codes::ATTR_AFTER]);
			$this->message = substr_replace($this->message, $tmp, $this->pos, 0);
			$ot_strlen = strlen($tmp);
			$this->pos += $ot_strlen;
			$this->pos1 += $ot_strlen;

			// Trim or eat trailing stuff... see comment at the end of the big loop.
			if (!empty($this->open_tags[$i][Codes::ATTR_BLOCK_LEVEL]) && substr_compare($this->message, '<br />', $this->pos, 6) === 0)
			{
				$this->message = substr_replace($this->message, '', $this->pos, 6);
			}

			if (isset($tag[Codes::ATTR_TRIM]) && $tag[Codes::ATTR_TRIM] !== Codes::TRIM_INSIDE)
			{
				$this->trimWhiteSpace($this->message, $this->pos);
			}

			$this->closeOpenedTag();
		}
	}

	/**
	 * Add markers around a string to denote that smileys should not be parsed
	 *
	 * @param string $string
	 *
	 * @return string
	 */
	protected function noSmileys($string)
	{
		return $this->smiley_marker . $string . $this->smiley_marker;
	}

	public function canCache()
	{
		return $this->can_cache;
	}

	// This is just so I can profile it.
	protected function filterData(array $tag, &$data)
	{
		$tag[Codes::ATTR_VALIDATE]($tag, $data, $this->bbc->getDisabled());
	}
}