from __future__ import annotations

import argparse
import json
import re
from collections import OrderedDict
from pathlib import Path
from typing import Any

from ehrman_demo_data import DEFAULT_KEYWORDS_PATH, ROOT, clean_string, normalize_keyword, unique_strings


DEFAULT_RAW_POSTS_PATH = ROOT / "data" / "raw" / "posts.jsonl"
MAX_DESCRIPTION_LENGTH = 340
MAX_SENTENCE_LENGTH = 300

APPROVED_DESCRIPTIONS_BY_URL = {
    "https://ehrmanblog.org/did-the-doctrine-of-predestination-lead-to-capitalism/":
        "Explains Max Weber’s thesis that Protestant predestination, Calvinist anxiety about election, and vocational calling helped foster the capitalist pursuit of profit.",
    "https://ehrmanblog.org/predestination-what-do-you-think/":
        "Surveys biblical passages and theological problems connected with predestination, divine sovereignty, grace, free will, Augustine, Calvin, and double predestination.",
    "https://ehrmanblog.org/doesnt-goodness-point-to-the-existence-of-god-and-gospel-preplexities-good-readers-questoins/":
        "Answers reader questions about goodness and God despite suffering, Luke’s handling of Jesus’ divine sonship, and the circulation of anonymous gospels.",
    "https://ehrmanblog.org/did-the-glories-of-martyrdom-lead-to-christian-conversions/":
        "Examines whether stories of Christian martyrs’ miraculous endurance helped persuade outsiders to convert, while noting that actual martyrdoms were probably not as numerous as later memory suggested.",
    "https://ehrmanblog.org/june-2026-gold-qa-announcement/":
        "Announces the June 2026 Gold and Platinum member Q&A, including the revised format, question deadline, live participation instructions, and selected topic of the Letters of Paul.",
    "https://ehrmanblog.org/the-fear-of-hell-as-an-incentive-to-convert/":
        "Explains how early Christian miracle stories and preaching about eternal hell could make conversion appear urgent and salvation appear necessary.",
    "https://ehrmanblog.org/was-augustine-telling-the-truth-about-miracles-hed-seen/":
        "Examines Augustine’s reports of miracles in his own day as evidence for how repeated miracle stories could support belief and conversion.",
    "https://ehrmanblog.org/biographical-accounts-of-early-christian-miracles-based-on-eyewitnesses/":
        "Examines later biographical stories about Gregory the Wonderworker and Martin of Tours, where confrontations with pagan powers and dramatic miracles lead people to embrace Christianity.",
    "https://ehrmanblog.org/and-the-miracles-just-keep-on-comin/":
        "Surveys legendary miracle stories outside the New Testament, including Edessa, Thaddaeus, John, and Peter, as examples of how Christians explained conversion through divine power.",
    "https://ehrmanblog.org/how-could-christian-miracles-convert-the-empire-if-miracles-dont-happen/":
        "Explains how miracle stories could produce conversions even if the miracles themselves did not happen, since people often believe reports they hear repeatedly from others.",
    "https://ehrmanblog.org/our-inner-herod-guest-post-by-glenn-siepert/":
        "Features Glenn Siepert’s excerpt from Emerging From the Rubble, reflecting on Herod in Matthew 2:1-12 and the challenge of confronting one’s own desire for control.",
    "https://ehrmanblog.org/you-have-no-right-to-question-why-you-suffer-what/":
        "Explains the climax of Job, where God refuses to explain Job’s innocent suffering and instead answers from the whirlwind by asserting divine power.",
    "https://ehrmanblog.org/active-pastors-who-have-lost-their-faith/":
        "Examines pastors who continue in ministry after losing their faith.",
    "https://ehrmanblog.org/bad-bible-jokes-and-do-you-have-any/":
        "Collects and comments on Bible jokes shared with readers.",
    "https://ehrmanblog.org/anniversary-post-7-doing-a-graduate-degree-in-early-christian-studies/":
        "Describes what it takes to pursue a graduate degree in early Christian studies.",
    "https://ehrmanblog.org/is-marks-seemingly-simple-gospel-unsophisticated-anniversary-post-6/":
        "Examines whether Mark's apparently simple Gospel is more sophisticated than it first appears.",
    "https://ehrmanblog.org/anniversary-post-5-why-i-was-reluctant-to-write-the-triumph-of-christianity/":
        "Explains why the author was reluctant to write The Triumph of Christianity.",
    "https://ehrmanblog.org/anniversary-post-4-why-gospels-matter-even-where-they-are-not-historical/":
        "Explores why the Gospels can matter even when particular stories are not historically accurate.",
    "https://ehrmanblog.org/a-recent-interview/":
        "Provides a link to a 2012 interview with the author.",
    "https://ehrmanblog.org/converting-the-world-why-has-christianity-always-been-missionary/":
        "Explores why Christianity has historically emphasized missionary outreach.",
    "https://ehrmanblog.org/chastity-within-marriage-paul-taught-that/":
        "Examines Paul's teaching on sexual abstinence within marriage.",
    "https://ehrmanblog.org/do-we-have-the-original-text-of-philippians-2/":
        "Considers whether the original text of Philippians can be recovered.",
    "https://ehrmanblog.org/do-we-have-the-original-text-of-philippians/":
        "Considers whether the original text of Philippians can be recovered.",
    "https://ehrmanblog.org/beginning-my-study-of-the-bible/":
        "Describes how the author began studying the Bible and eventually came to write trade books.",
    "https://ehrmanblog.org/you-wont-find-this-in-the-new-testament/":
        "Describes a noncanonical account focused on Mary, the mother of Jesus.",
    "https://ehrmanblog.org/christ-as-an-angel-in-paul-2/":
        "Examines whether Paul understood the pre-existent Christ as an angelic being.",
    "https://ehrmanblog.org/49814-2/":
        "Uses early Christian conversion accounts to explain why miracle stories mattered for Christianity's growth.",
    "https://ehrmanblog.org/why-do-are-so-many-textual-critics-evangelicals-readers-mailbag/":
        "Answers a reader question about why many textual critics are evangelicals.",
    "https://ehrmanblog.org/superior-health-care-as-an-explanation-for-the-spread-of-christianity/":
        "Evaluates the modern claim that Christianity spread because Christian communities provided better health care during epidemics.",
    "https://ehrmanblog.org/a-modern-common-sense-about-what-made-christianity-attractive-to-converts/":
        "Examines the claim that Christianity attracted converts because Christian communities offered unusually strong social support.",
    "https://ehrmanblog.org/what-an-ancient-enemy-of-christianity-said-about-why-it-was-successful/":
        "Uses Celsus's critique of Christianity to show how one ancient pagan opponent understood Christian success.",
    "https://ehrmanblog.org/how-did-christianity-succeed-an-older-view-that-many-people-still-have/":
        "Critiques an older scholarly explanation for Christianity's success and contrasts it with conversion, exclusivity, and mission.",
    "https://ehrmanblog.org/do-you-know-the-golden-ass-is-a-mystery-religion-like-christianity/":
        "Introduces Apuleius's The Golden Ass as evidence for ancient mystery religion and Greco-Roman religious experience.",
    "https://ehrmanblog.org/christianity-a-weirdly-exclusivist-religion/":
        "Explains how Christian exclusivity made the movement unusual in the Roman world and helped reshape religious allegiance.",
    "https://ehrmanblog.org/some-important-readers-questions-on-some-gospel-head-scratchers/":
        "Responds to reader questions about Luke's Christology, Gospel tensions, and how New Testament writers handle Jesus' identity.",
    "https://ehrmanblog.org/how-early-christians-made-converts-tent-revivals/":
        "Examines how early Christians made converts without modern-style rallies, mass campaigns, or organized door-to-door evangelism.",
    "https://ehrmanblog.org/blog-dinner-in-waynesville-nc-may-19-wanna-come/":
        "Announces a blog dinner in Waynesville, North Carolina, with attendance details for interested members.",
    "https://ehrmanblog.org/jesus-and-capitalism-my-next-book-a-big-change/":
        "Announces a planned book on Jesus, capitalism, socialism, and related economic questions from a New Testament perspective.",
    "https://ehrmanblog.org/want-to-be-involved-in-more-in-depth-discussion-of-key-issues-a-blog-opportunity/":
        "Introduces the Blog Stewards group as a way for members to discuss New Testament and early Christianity topics in more depth.",
    "https://ehrmanblog.org/a-common-but-lousy-argument-that-we-know-what-the-nt-originally-said-anniversary-post-14/":
        "Critiques the argument that abundant New Testament manuscripts prove secure access to the original text.",
    "https://ehrmanblog.org/sailing-cruise-to-caribbean-islands-in-january-want-to-come-with-me/":
        "Announces a January 2027 Caribbean sailing cruise and describes the trip format, ship, and lecture component.",
    "https://ehrmanblog.org/the-morality-of-war/":
        "Reflects on the morality of war while explaining why the blog normally avoids direct political commentary.",
    "https://ehrmanblog.org/may-2026-gold-qa-announcement-were-trying-something-new/":
        "Announces a May 2026 Gold and Platinum member Q&A with a revised format that includes live participant questions.",
    "https://ehrmanblog.org/nile-cruise-cancellation/":
        "Announces the cancellation or postponement of the planned Nile cruise because of conditions in the Middle East.",
    "https://ehrmanblog.org/did-paul-think-of-jesus-as-the-incarnation-of-an-angel-anniverary-post-13/":
        "Explains the argument that Paul understood Christ's pre-existent state in angelic terms.",
    "https://ehrmanblog.org/an-amazing-fragment-of-a-lost-gospel-anniversary-post-12/":
        "Discusses a fragmentary lost gospel, possibly related to the Gospel of Peter, featuring a conversation between Jesus and Peter.",
    "https://ehrmanblog.org/the-seven-sleepers-of-ephesus-platinum-post-by-douglas-wadeson-md/":
        "Presents the legend of the Seven Sleepers of Ephesus, including its Christian setting and later appearance in the Qur'an.",
    "https://ehrmanblog.org/a-letter-written-by-jesus-anniversary-post-10/":
        "Discusses ancient traditions claiming that Jesus corresponded with King Abgar of Edessa.",
    "https://ehrmanblog.org/anniversary-post-9-misquoting-misquoting-jesus-2/":
        "Explains how Misquoting Jesus has often been misunderstood, especially by critics concerned about its implications for faith.",
    "https://ehrmanblog.org/may-2026-platinum-webinar-announcement/":
        "Announces a Platinum member webinar on the Gospel of Thomas with date, time, access details, and replay information.",
    "https://ehrmanblog.org/different-words-very-different-theologies-and-understanding-which-words-they-were-readers-questions/":
        "Responds to reader questions about resurrection language, textual wording, and theological meaning in New Testament interpretation.",
    "https://ehrmanblog.org/anniversary-post-9-when-is-a-contradiction-not-a-contradiction/":
        "Explains what counts as a contradiction in Gospel comparison and why definitions matter in debates about biblical discrepancies.",
    "https://ehrmanblog.org/does-god-care-what-we-wear-a-platinum-post-by-douglas-wadeson-md/":
        "Examines religious clothing rules in Judaism, Christianity, and Islam, asking whether dress regulations have scriptural grounding.",
    "https://ehrmanblog.org/our-anniversary-celebration-for-14-years-of-the-blog-check-out-the-unusual-qa/":
        "Shares the recording of a fourteenth-anniversary Q&A built around playful historical hypotheticals.",
    "https://ehrmanblog.org/anniversary-post-3-my-response-to-an-ill-tempered-richard-carrier/":
        "Responds to Richard Carrier's criticism of How Jesus Became God and the historical claims involved.",
    "https://ehrmanblog.org/anniversary-post-2-why-were-the-gospels-written-anonymously/":
        "Explains why the New Testament Gospels circulated anonymously and why that differs from some other ancient writings.",
    "https://ehrmanblog.org/anniversary-post-1-defending-misquoting-jesus/":
        "Defends Misquoting Jesus against criticisms that the book exaggerated or distorted the problem of textual variation.",
    "https://ehrmanblog.org/celebrating-the-blogs-14th-anniversary-do-you-have-a-favorite-post/":
        "Marks the blog's fourteenth anniversary by inviting readers to nominate favorite posts for republication.",
    "https://ehrmanblog.org/the-distinctively-jewish-roots-of-jesus-ethics/":
        "Shows how Jesus' ethical teachings stand within Jewish scripture and Jewish moral teaching rather than apart from them.",
    "https://ehrmanblog.org/understanding-the-gospels-jesus-and-the-spread-of-christianity-great-readers-questions/":
        "Responds to reader questions about Jewish proselytizing, Christian word-of-mouth conversion, and Jesus' Son of Man sayings.",
}

APPROVED_DESCRIPTIONS_BY_URL.update({
    "https://ehrmanblog.org/did-the-doctrine-of-predestination-lead-to-capitalism/":
        "Explains Max Weber's argument that Protestant predestination, especially in Calvinist thought, helped shape the modern capitalist drive for disciplined profit.",
    "https://ehrmanblog.org/predestination-what-do-you-think/":
        "Surveys biblical passages and theological problems connected with predestination, grace, divine sovereignty, free will, Augustine, Calvin, and double predestination.",
    "https://ehrmanblog.org/doesnt-goodness-point-to-the-existence-of-god-and-gospel-preplexities-good-readers-questoins/":
        "Responds to reader questions about goodness and suffering, Luke's presentation of Jesus' divine sonship, and how anonymous gospels circulated.",
    "https://ehrmanblog.org/did-the-glories-of-martyrdom-lead-to-christian-conversions/":
        "Examines whether stories of Christian martyrs' endurance under torture helped persuade outsiders to convert.",
    "https://ehrmanblog.org/june-2026-gold-qa-announcement/":
        "Announces the June 2026 Gold and Platinum member Q&A, including the format, submission deadline, live participation instructions, and selected topic.",
    "https://ehrmanblog.org/the-fear-of-hell-as-an-incentive-to-convert/":
        "Explains how Christian preaching about hell could make conversion seem urgent, especially when paired with stories of divine power and miracles.",
    "https://ehrmanblog.org/was-augustine-telling-the-truth-about-miracles-hed-seen/":
        "Examines Augustine's reports of miracles as evidence for how miracle claims functioned in Christian arguments for conversion.",
    "https://ehrmanblog.org/biographical-accounts-of-early-christian-miracles-based-on-eyewitnesses/":
        "Discusses later Christian biographies of Gregory the Wonderworker and Martin of Tours as miracle-centered stories of conversion and religious power.",
    "https://ehrmanblog.org/and-the-miracles-just-keep-on-comin/":
        "Surveys legendary miracle stories outside the New Testament, including traditions about Edessa, Thaddaeus, John, and Peter.",
    "https://ehrmanblog.org/how-could-christian-miracles-convert-the-empire-if-miracles-dont-happen/":
        "Explains how miracle stories could promote conversion even if the miracles themselves did not happen.",
    "https://ehrmanblog.org/49814-2/":
        "Argues that early Christian conversion accounts most often explain Christian success by appealing to miracles.",
    "https://ehrmanblog.org/superior-health-care-as-an-explanation-for-the-spread-of-christianity/":
        "Evaluates the modern claim that Christianity spread because Christian communities provided better health care during epidemics.",
    "https://ehrmanblog.org/a-modern-common-sense-about-what-made-christianity-attractive-to-converts/":
        "Examines the claim that Christianity attracted converts because Christian communities offered unusually strong social support.",
    "https://ehrmanblog.org/what-an-ancient-enemy-of-christianity-said-about-why-it-was-successful/":
        "Uses Celsus's critique of Christianity to show how one ancient pagan opponent understood Christian success.",
    "https://ehrmanblog.org/how-did-christianity-succeed-an-older-view-that-many-people-still-have/":
        "Critiques an older scholarly explanation for Christianity's success and contrasts it with conversion, exclusivity, and mission.",
    "https://ehrmanblog.org/do-you-know-the-golden-ass-is-a-mystery-religion-like-christianity/":
        "Introduces Apuleius's The Golden Ass as evidence for ancient mystery religion and Greco-Roman religious experience.",
    "https://ehrmanblog.org/christianity-a-weirdly-exclusivist-religion/":
        "Explains how Christian exclusivity made the movement unusual in the Roman world and helped reshape religious allegiance.",
    "https://ehrmanblog.org/some-important-readers-questions-on-some-gospel-head-scratchers/":
        "Responds to reader questions about Luke's Christology, Gospel tensions, and how New Testament writers handle Jesus' identity.",
    "https://ehrmanblog.org/how-early-christians-made-converts-tent-revivals/":
        "Examines how early Christians made converts without modern-style rallies, mass campaigns, or organized door-to-door evangelism.",
    "https://ehrmanblog.org/converting-the-world-why-has-christianity-always-been-missionary/":
        "Explores why Christianity became a missionary religion and why that feature mattered for its spread in the Roman world.",
    "https://ehrmanblog.org/blog-dinner-in-waynesville-nc-may-19-wanna-come/":
        "Announces a blog dinner in Waynesville, North Carolina, with attendance details for interested members.",
    "https://ehrmanblog.org/jesus-and-capitalism-my-next-book-a-big-change/":
        "Announces a planned book on Jesus, capitalism, socialism, and related economic questions from a New Testament perspective.",
    "https://ehrmanblog.org/want-to-be-involved-in-more-in-depth-discussion-of-key-issues-a-blog-opportunity/":
        "Introduces the Blog Stewards group as a way for members to discuss New Testament and early Christianity topics in more depth.",
    "https://ehrmanblog.org/a-common-but-lousy-argument-that-we-know-what-the-nt-originally-said-anniversary-post-14/":
        "Critiques the argument that abundant New Testament manuscripts prove secure access to the original text.",
    "https://ehrmanblog.org/sailing-cruise-to-caribbean-islands-in-january-want-to-come-with-me/":
        "Announces a January 2027 Caribbean sailing cruise and describes the trip format, ship, and lecture component.",
    "https://ehrmanblog.org/the-morality-of-war/":
        "Reflects on the morality of war while explaining why the blog normally avoids direct political commentary.",
    "https://ehrmanblog.org/may-2026-gold-qa-announcement-were-trying-something-new/":
        "Announces a May 2026 Gold and Platinum member Q&A with a revised format that includes live participant questions.",
    "https://ehrmanblog.org/nile-cruise-cancellation/":
        "Announces the cancellation or postponement of the planned Nile cruise because of conditions in the Middle East.",
    "https://ehrmanblog.org/did-paul-think-of-jesus-as-the-incarnation-of-an-angel-anniverary-post-13/":
        "Explains the argument that Paul understood Christ's pre-existent state in angelic terms.",
    "https://ehrmanblog.org/an-amazing-fragment-of-a-lost-gospel-anniversary-post-12/":
        "Discusses a fragmentary lost gospel, possibly related to the Gospel of Peter, featuring a conversation between Jesus and Peter.",
    "https://ehrmanblog.org/active-pastors-who-have-lost-their-faith/":
        "Discusses pastors who remain in ministry after losing belief and why leaving the pulpit can be difficult.",
    "https://ehrmanblog.org/the-seven-sleepers-of-ephesus-platinum-post-by-douglas-wadeson-md/":
        "Presents the legend of the Seven Sleepers of Ephesus, including its Christian setting and later appearance in the Qur'an.",
    "https://ehrmanblog.org/a-letter-written-by-jesus-anniversary-post-10/":
        "Discusses ancient traditions claiming that Jesus corresponded with King Abgar of Edessa.",
    "https://ehrmanblog.org/anniversary-post-9-misquoting-misquoting-jesus-2/":
        "Explains how Misquoting Jesus has often been misunderstood, especially by critics concerned about its implications for faith.",
    "https://ehrmanblog.org/may-2026-platinum-webinar-announcement/":
        "Announces a Platinum member webinar on the Gospel of Thomas with date, time, access details, and replay information.",
    "https://ehrmanblog.org/different-words-very-different-theologies-and-understanding-which-words-they-were-readers-questions/":
        "Responds to reader questions about resurrection language, textual wording, and theological meaning in New Testament interpretation.",
    "https://ehrmanblog.org/bad-bible-jokes-and-do-you-have-any/":
        "Collects Bible jokes prompted by Genesis and other biblical allusions, while inviting readers to add more.",
    "https://ehrmanblog.org/anniversary-post-9-when-is-a-contradiction-not-a-contradiction/":
        "Explains what counts as a contradiction in Gospel comparison and why definitions matter in debates about biblical discrepancies.",
    "https://ehrmanblog.org/does-god-care-what-we-wear-a-platinum-post-by-douglas-wadeson-md/":
        "Examines religious clothing rules in Judaism, Christianity, and Islam, asking whether dress regulations have scriptural grounding.",
    "https://ehrmanblog.org/our-anniversary-celebration-for-14-years-of-the-blog-check-out-the-unusual-qa/":
        "Shares the recording of a fourteenth-anniversary Q&A built around playful historical hypotheticals.",
    "https://ehrmanblog.org/anniversary-post-7-doing-a-graduate-degree-in-early-christian-studies/":
        "Explains why pursuing a PhD in New Testament or early Christianity is far more demanding than many prospective students expect.",
    "https://ehrmanblog.org/is-marks-seemingly-simple-gospel-unsophisticated-anniversary-post-6/":
        "Argues that Mark's Gospel is more literarily sophisticated than its simple style may first suggest.",
    "https://ehrmanblog.org/anniversary-post-5-why-i-was-reluctant-to-write-the-triumph-of-christianity/":
        "Explains why writing The Triumph of Christianity seemed unusually difficult because of the scope of the historical question.",
    "https://ehrmanblog.org/anniversary-post-4-why-gospels-matter-even-where-they-are-not-historical/":
        "Argues that the Gospels can matter even when particular stories are not historically accurate, because memory also shapes meaning.",
    "https://ehrmanblog.org/anniversary-post-3-my-response-to-an-ill-tempered-richard-carrier/":
        "Responds to Richard Carrier's criticism of How Jesus Became God and the historical claims involved.",
    "https://ehrmanblog.org/anniversary-post-2-why-were-the-gospels-written-anonymously/":
        "Explains why the New Testament Gospels circulated anonymously and why that differs from some other ancient writings.",
    "https://ehrmanblog.org/anniversary-post-1-defending-misquoting-jesus/":
        "Defends Misquoting Jesus against criticisms that the book exaggerated or distorted the problem of textual variation.",
    "https://ehrmanblog.org/celebrating-the-blogs-14th-anniversary-do-you-have-a-favorite-post/":
        "Marks the blog's fourteenth anniversary by inviting readers to nominate favorite posts for republication.",
    "https://ehrmanblog.org/the-distinctively-jewish-roots-of-jesus-ethics/":
        "Shows how Jesus' ethical teachings stand within Jewish scripture and Jewish moral teaching rather than apart from them.",
    "https://ehrmanblog.org/understanding-the-gospels-jesus-and-the-spread-of-christianity-great-readers-questions/":
        "Responds to reader questions about Jewish proselytizing, Christian word-of-mouth conversion, and Jesus' Son of Man sayings.",
})

ADMIN_TITLE_TERMS = (
    "announcement",
    "interview",
    "invited",
    "podcast",
    "webinar",
    "q&a",
    "gold q",
    "platinum",
    "course",
    "event",
    "dinner",
    "cruise",
    "trip",
    "travel",
    "membership",
    "subscription",
)

BOILERPLATE_PATTERNS = (
    r"^view larger image$",
    r"^for the rest of this post\b",
    r"^membership content continues\b",
    r"^click here for membership\b",
    r"^if you don.t belong yet\b",
    r"^get with the program\b",
    r"^\*+$",
    r"^-+$",
)

WEAK_SENTENCE_STARTS = (
    "as i indicated",
    "as it turns out",
    "as i’ve",
    "as i've",
    "as i pointed out",
    "as i said",
    "as i mentioned",
    "as you may",
    "but ",
    "but,",
    "a couple of readers",
    "each week just now",
    "for over ",
    "for the rest",
    "here i ",
    "here is what",
    "here is",
    "here’s what",
    "here’s",
    "here's what",
    "here's",
    "it ",
    "it’s",
    "it's",
    "itâ€™s",
    "it doesn",
    "i ",
    "i’m ",
    "i continue here",
    "i have received",
    "i have often",
    "i received",
    "i think",
    "i will be dealing",
    "i’ll be dealing",
    "i’ve ",
    "in previous",
    "in an earlier post",
    "in this article",
    "in my head",
    "in my previous post",
    "in the previous post",
    "in this post",
    "in this nutshell series",
    "let me ",
    "membership content",
    "my ",
    "now that",
    "now we",
    "not wanting",
    "one more issue",
    "over the past",
    "on the surface",
    "on very rare occasions",
    "q:",
    "question:",
    "response:",
    "the question",
    "the question itself",
    "there are",
    "there is",
    "this one",
    "this post",
    "this article",
    "this will be my final",
    "to celebrate",
    "today",
    "yesterday",
)

TITLE_STOP_WORDS = {
    "a",
    "about",
    "after",
    "all",
    "an",
    "and",
    "are",
    "as",
    "at",
    "be",
    "but",
    "by",
    "can",
    "did",
    "do",
    "does",
    "for",
    "from",
    "had",
    "has",
    "have",
    "he",
    "her",
    "his",
    "how",
    "i",
    "if",
    "in",
    "is",
    "it",
    "its",
    "me",
    "my",
    "not",
    "of",
    "on",
    "or",
    "our",
    "part",
    "post",
    "that",
    "the",
    "their",
    "there",
    "these",
    "they",
    "this",
    "to",
    "was",
    "we",
    "were",
    "what",
    "when",
    "where",
    "who",
    "why",
    "with",
    "would",
    "you",
}

DID_VERB_FORMS = {
    "believe": "believed",
    "betray": "betrayed",
    "become": "become",
    "call": "called",
    "change": "changed",
    "convert": "converted",
    "create": "created",
    "deny": "denied",
    "die": "died",
    "exist": "existed",
    "expect": "expected",
    "find": "found",
    "go": "went",
    "happen": "happened",
    "have": "had",
    "include": "included",
    "invent": "invented",
    "know": "knew",
    "live": "lived",
    "make": "made",
    "marry": "married",
    "mean": "meant",
    "predict": "predicted",
    "produce": "produced",
    "read": "read",
    "receive": "received",
    "return": "returned",
    "rise": "rise",
    "say": "said",
    "see": "saw",
    "speak": "spoke",
    "teach": "taught",
    "think": "thought",
    "understand": "understood",
    "use": "used",
    "write": "wrote",
}

LEADING_FRAME_PATTERNS = (
    (r"^(?:QUESTION|Q|RESPONSE|ANSWER)\s*:\s*", ""),
    (r"^But[, ]+\s*", ""),
    (r"^In an earlier post[^,.;:]*[,.;:]\s*", ""),
    (r"^In previous posts?[^,.;:]*[,.;:]\s*", ""),
    (r"^In (?:my|the) previous (?:\w+\s+)?posts?[^,.;:]*[,.;:]\s*", ""),
    (r"^In my head[^,.;:]*[,.;:]\s*", ""),
    (r"^In this [“\"]?nutshell[”\"]? series[^,.;:]*[,.;:]\s*", ""),
    (r"^In this post\s+I\s+(?:want to|will|am going to)\s+", ""),
    (r"^Here\s+I\s+(?:want to|will|pick up on|explain|discuss)\s+", ""),
    (r"^Here\s+now\s+is\s+", ""),
    (r"^Here(?:’|')s\s+(?:a|an|the)?\s*(?:post|question|interesting question)?\s*(?:that|about)?\s*", ""),
    (r"^This post\s+(?:is|will be)\s+(?:about|on)\s+", ""),
    (r"^This one\s+is\s+(?:from|on|about)\s+", ""),
    (r"^As you may (?:know|have noticed|remember)[^,.;:]*[,.;:]\s*", ""),
    (r"^As it turns out[,.;:]?\s*", ""),
    (r"^Now that\s+[^,.;:]*[,.;:]\s*", ""),
    (r"^Yesterday(?:'s post)?[^,.;:]*[,.;:]\s*", ""),
    (r"^A few days ago[^,.;:]*[,.;:]\s*", ""),
    (r"^For over [^,.;:]*[,.;:]\s*", ""),
    (r"^Not wanting to go away empty-handed[,.;:]?\s*", ""),
    (r"^To celebrate [^,.;:]*[,.;:]\s*", ""),
    (r"^One more issue connected with\s+", ""),
)


def load_raw_posts(path: Path) -> dict[str, dict[str, Any]]:
    raw_posts: dict[str, dict[str, Any]] = {}
    with path.open("r", encoding="utf-8") as handle:
        for line in handle:
            if not line.strip():
                continue
            post = json.loads(line)
            url = clean_string(post.get("url"))
            if url:
                raw_posts[url] = post
    return raw_posts


def clean_title(title: Any) -> str:
    value = clean_string(title)
    value = re.sub(r"\s+", " ", value)
    return value.strip(" .")


def normalize_spacing(value: str) -> str:
    value = value.replace("\u00a0", " ")
    value = re.sub(r"\s+", " ", value)
    return value.strip()


def clean_text(text: Any, title: str, date_text: str) -> str:
    lines: list[str] = []
    for raw_line in clean_string(text).splitlines():
        line = normalize_spacing(raw_line)
        if not line:
            continue
        if line == title or line == date_text:
            continue
        if any(re.search(pattern, line, flags=re.IGNORECASE) for pattern in BOILERPLATE_PATTERNS):
            continue
        lines.append(line)
    return normalize_spacing(" ".join(lines))


def split_sentences(text: str) -> list[str]:
    pieces = re.split(r"(?<=[.!?])\s+(?=[A-Z0-9“\"'(])", text)
    sentences: list[str] = []
    for piece in pieces:
        sentence = normalize_spacing(piece)
        if not sentence:
            continue
        sentence = re.sub(r"\[\d+\]", "", sentence)
        if len(sentence) < 25:
            continue
        sentences.append(sentence)
    return sentences


def title_keywords(title: str) -> set[str]:
    words = re.findall(r"[A-Za-z0-9]+", title.lower())
    return {word for word in words if len(word) > 3 and word not in TITLE_STOP_WORDS}


def is_weak_sentence(sentence: str) -> bool:
    lowered = sentence.lower()
    return lowered.startswith(WEAK_SENTENCE_STARTS)


def sentence_score(sentence: str, title_terms: set[str], index: int) -> int:
    lowered = sentence.lower()
    score = max(0, 12 - index)
    score += sum(2 for term in title_terms if term in lowered)
    if 80 <= len(sentence) <= 260:
        score += 4
    if any(term in lowered for term in ("argue", "thesis", "point", "explain", "shows", "showing", "focus", "question", "why", "how")):
        score += 3
    if is_weak_sentence(sentence):
        score -= 35
    if lowered.startswith(("but ", "and ", "or ", "so ", "yes,", "no,")):
        score -= 3
    if sentence.count("?") > 1:
        score -= 2
    return score


def select_key_sentence(sentences: list[str], title: str) -> str:
    if not sentences:
        return ""
    terms = title_keywords(title)
    candidates = sentences[:16]
    return max(candidates, key=lambda sentence: sentence_score(sentence, terms, candidates.index(sentence)))


def strip_first_person_frame(sentence: str) -> str:
    value = normalize_spacing(sentence).strip(" .")
    replacements = [
        (r"^(?:QUESTION|Q|RESPONSE|ANSWER)\s*:\s*", ""),
        (r"^I[’']ve been arguing that\s+", ""),
        (r"^I have been arguing that\s+", ""),
        (r"^I[’']ve indicated that\s+", ""),
        (r"^I have indicated that\s+", ""),
        (r"^I[’']ve often talked about\s+", ""),
        (r"^I have often talked about\s+", ""),
        (r"^I[’']m talking about\s+", ""),
        (r"^I am talking about\s+", ""),
        (r"^I argue that\s+", "the claim that "),
        (r"^I will argue that\s+", "the claim that "),
        (r"^I want to explain\s+", ""),
        (r"^I want to show\s+", ""),
        (r"^I[’']ll explain\s+", ""),
        (r"^In this post I want to\s+", ""),
        (r"^This post is about\s+", ""),
        (r"^The point has to do with\s+", ""),
        (r"^The point is that\s+", "the point that "),
        (r"^The key point is that\s+", "the point that "),
    ]
    for pattern, replacement in replacements:
        value = re.sub(pattern, replacement, value, flags=re.IGNORECASE)
    for _ in range(2):
        for pattern, replacement in LEADING_FRAME_PATTERNS:
            value = re.sub(pattern, replacement, value, flags=re.IGNORECASE)
    value = value.strip(" .")
    value = re.sub(r"^about\s+(how|why|whether|what|who|when|where)\b", r"\1", value, flags=re.IGNORECASE)
    value = re.sub(r"^about\s+", "", value, flags=re.IGNORECASE)
    if value.lower().startswith("that "):
        value = "the claim " + value
    return value


def question_to_phrase(value: str) -> str:
    phrase = normalize_spacing(value).strip(" .")
    phrase = phrase.rstrip("?")
    phrase = re.sub(r"\bAccording to\b", "according to", phrase)
    match = re.match(r"^You\s+Don[’']t\s+Think\s+(.+)$", phrase, flags=re.IGNORECASE)
    if match:
        return "whether " + lower_first_word(match.group(1))
    match = re.match(r"^How\s+Can\s+You\s+(.+)$", phrase, flags=re.IGNORECASE)
    if match:
        return "how one can " + lower_first_word(match.group(1))
    match = re.match(r"^How\s+I\s+Write\b:?\s*(.*)$", phrase, flags=re.IGNORECASE)
    if match:
        rest = match.group(1).strip(" .,:;-")
        return "how the author writes" + (f", especially {lower_first_word(rest)}" if rest else "")
    match = re.match(r"^Are\s+You\s+Curious\s+About\s+(.+)$", phrase, flags=re.IGNORECASE)
    if match:
        return lower_first_word(match.group(1))
    match = re.match(r"^Why\s+Have\s+I\s+(.+)$", phrase, flags=re.IGNORECASE)
    if match:
        return "why the author has " + lower_first_word(match.group(1))
    match = re.match(r"^Why\s+I\s+Want\s+to\s+(.+)$", phrase, flags=re.IGNORECASE)
    if match:
        return "why the author wants to " + lower_first_word(match.group(1))
    match = re.match(r"^Do\s+I\s+(.+)$", phrase, flags=re.IGNORECASE)
    if match:
        rest = match.group(1)
        rest = re.sub(r"^Need\s+to\s+", "", rest, flags=re.IGNORECASE)
        rest = re.sub(r"^Need\s+", "", rest, flags=re.IGNORECASE)
        return "whether the author needs to " + lower_first_word(rest)
    match = re.match(r"^What\s+Do\s+I\s+Think\s+of\s+(.+)$", phrase, flags=re.IGNORECASE)
    if match:
        return "what the author thinks of " + lower_first_word(match.group(1))
    match = re.match(r"^What\s+Did\s+(.+)$", phrase, flags=re.IGNORECASE)
    if match:
        return "what " + lower_first_word(convert_did_rest_to_past(match.group(1)))
    match = re.match(r"^What\s+(Is|Are|Was|Were)\s+(.+?)\s+About$", phrase, flags=re.IGNORECASE)
    if match:
        aux = match.group(1).lower()
        rest = lower_first_word(match.group(2))
        return f"what {rest} {aux} about"
    match = re.match(r"^What\s+(Is|Are|Was|Were)\s+(.+)$", phrase, flags=re.IGNORECASE)
    if match:
        aux = match.group(1).lower()
        rest = lower_first_word(match.group(2))
        return f"what {rest} {aux}"
    match = re.match(r"^Who\s+(Is|Are|Was|Were)\s+(.+)$", phrase, flags=re.IGNORECASE)
    if match:
        aux = match.group(1).lower()
        rest = lower_first_word(match.group(2))
        return f"who {rest} {aux}"
    match = re.match(r"^Why\s+I\s+Was\s+(.+)$", phrase, flags=re.IGNORECASE)
    if match:
        return "why the author was " + lower_first_word(match.group(1))
    match = re.match(r"^Why\s+Are\s+So\s+Many\s+(.+)$", phrase, flags=re.IGNORECASE)
    if match:
        return "why so many " + lower_first_word(match.group(1)) + " are"
    match = re.match(r"^Why\s+(Has|Have|Had|Is|Are|Was|Were|Does|Do|Did|Can|Could|Would|Should|Will)\s+(.+)$", phrase, flags=re.IGNORECASE)
    if match:
        aux = match.group(1).lower()
        rest = match.group(2)
        parts = rest.split(" ", 1)
        if len(parts) == 2:
            return f"why {parts[0]} {aux} {lower_first_word(parts[1])}"
        return "why " + lower_first_word(rest)
    match = re.match(r"^If\s+(.+)$", phrase, flags=re.IGNORECASE)
    if match:
        return "whether " + lower_first_word(match.group(1))
    match = re.match(r"^(Did|Does|Do)\s+(.+)$", phrase, flags=re.IGNORECASE)
    if match:
        aux = match.group(1).lower()
        rest = match.group(2)
        if aux == "did":
            rest = convert_did_rest_to_past(rest)
        return "whether " + lower_first_word(rest)
    match = re.match(r"^(Why|How|What|Who|When|Where)\s+(.+)$", phrase, flags=re.IGNORECASE)
    if match:
        return match.group(1).lower() + " " + lower_first_word(match.group(2))
    match = re.match(r"^(Is|Are|Was|Were|Can|Could|Should|Would|Will)\s+(.+)$", phrase, flags=re.IGNORECASE)
    if match:
        aux = match.group(1).lower()
        rest = match.group(2)
        if aux in {"is", "was"}:
            parts = rest.split(" ", 1)
            if len(parts) == 2 and parts[0].lower() not in {"a", "an", "the"}:
                return f"whether {parts[0]} {aux} {lower_first_word(parts[1])}"
        return "whether " + lower_first_word(rest)
    return phrase


def starts_with_first_person(value: str) -> bool:
    return bool(re.match(r"^(i|i’m|i've|i’ve|i'd|i’d|i'll|i’ll|my|we|we’re|we've|we’ve)\b", value.strip(), flags=re.IGNORECASE))


def lower_first_word(value: str) -> str:
    if not value:
        return value
    if value.startswith(("Jesus", "Paul", "God", "Christ", "Luke", "Mark", "Matthew", "John", "Augustine", "Weber", "Judas", "Peter", "Mary", "Moses", "David", "Gnosticism")):
        return value
    return value[:1].lower() + value[1:]


def convert_did_rest_to_past(rest: str) -> str:
    tokens = rest.split()
    for index, token in enumerate(tokens):
        key = re.sub(r"[^A-Za-z]", "", token).lower()
        if index == 0 or key not in DID_VERB_FORMS:
            continue
        replacement = DID_VERB_FORMS[key]
        tokens[index] = re.sub(r"[A-Za-z]+", replacement, token, count=1)
        if index + 1 < len(tokens) and tokens[index + 1] in {"A", "An", "The", "That"}:
            tokens[index + 1] = tokens[index + 1].lower()
        return " ".join(tokens)
    return rest


def truncate_phrase(value: str, max_length: int = 230) -> str:
    value = normalize_spacing(value).strip(" .")
    if len(value) <= max_length:
        return value
    truncated = value[:max_length].rstrip()
    for marker in ("; ", ": ", ", ", " — ", " – "):
        cut = truncated.rfind(marker)
        if cut >= 90:
            return truncated[:cut].strip(" .")
    last_space = truncated.rfind(" ")
    return truncated[:last_space].strip(" .") if last_space > 90 else truncated.strip(" .")


def quote_title(title: str) -> str:
    return f"“{clean_title(title)}”"


def clean_title_topic(title: str) -> str:
    value = clean_title(title)
    value = re.sub(r"\bAnniversary Post\s*#?\d+\b", "", value, flags=re.IGNORECASE)
    value = re.sub(r"\bA Blast from the Past\b", "", value, flags=re.IGNORECASE)
    value = re.sub(r"\bReaders[’']?\s+Mailbag\b", "", value, flags=re.IGNORECASE)
    value = re.sub(r"\bReaders[’']?\s+Questions\b", "", value, flags=re.IGNORECASE)
    value = re.sub(r"\bQuestions?\s+and\s+Responses?\b", "", value, flags=re.IGNORECASE)
    value = re.sub(r"\bPart\s+\d+\b", "", value, flags=re.IGNORECASE)
    value = re.sub(r"^At Last\.?\s*", "", value, flags=re.IGNORECASE)
    value = re.sub(r"^Why\s+Do\s+Are\s+", "Why Are ", value, flags=re.IGNORECASE)
    value = value.strip(" .,:;-")
    if "?" in value and re.match(r"^(why|how|what|who|when|where|did|does|do|is|are|was|were|can|could|should|would|will)\b", value, flags=re.IGNORECASE):
        value = value.split("?", 1)[0] + "?"
    value = re.sub(r"^So\s+(?=(?:why|how|what|who|when|where|did|does|do|is|are|was|were|can|could|should|would|will)\b)", "", value, flags=re.IGNORECASE)
    colon_parts = re.split(r"\s*[:]\s*", value, maxsplit=1)
    if len(colon_parts) == 2 and re.match(r"^(why|how|what|who|when|where|did|does|do|is|are|was|were|can|could|should|would|will)\b", colon_parts[1], flags=re.IGNORECASE):
        value = colon_parts[1]
    value = re.sub(r"^My\s+", "the author's ", value, flags=re.IGNORECASE)
    value = re.sub(r"\s+[:–—-]\s*$", "", value)
    value = re.sub(r"\s+", " ", value)
    return value.strip(" .,:;-")


def smooth_title_phrase(value: str) -> str:
    phrase = normalize_spacing(value)
    replacements = {
        " Always ": " always ",
        " Been ": " been ",
        " Became ": " became ",
        " Being ": " being ",
        " More ": " more ",
        " Involved ": " involved ",
        " The ": " the ",
        " A ": " a ",
        " An ": " an ",
        " Of ": " of ",
        " To ": " to ",
        " From ": " from ",
        " In ": " in ",
        " On ": " on ",
        " For ": " for ",
        " And ": " and ",
        " Or ": " or ",
        " But ": " but ",
        " With ": " with ",
        " Without ": " without ",
        " About ": " about ",
        " Into ": " into ",
        " Over ": " over ",
        " Under ": " under ",
        " By ": " by ",
        " As ": " as ",
        " That ": " that ",
        " No ": " no ",
        " Gets ": " gets ",
        " Authorities": " authorities",
        " Secretaries": " secretaries",
        " Enemies": " enemies",
        " Him": " him",
        " Them": " them",
        " Letter": " letter",
        " Book ": " book ",
        " Books": " books",
        " Christian Love": " Christian love",
        " Crucial Phase": " crucial phase",
        " Original ": " original ",
        " Text": " text",
        " Write ": " write ",
        " Read ": " read ",
        " Incarnation ": " incarnation ",
        " Angel": " angel",
        "Missionary": "missionary",
        " Religion": " religion",
        " Missionaries": " missionaries",
        " Still ": " still ",
        " Up ": " up ",
        " Grabs ": " grabs ",
        " Reactions ": " reactions ",
        " “Heresies”": " heresies",
        " Heresies": " heresies",
        " Rise ": " rise ",
        " Accuracy ": " accuracy ",
        " Persecutions": " persecutions",
        " Anti-Judaism": " anti-Judaism",
        " Very ": " very ",
        " Odd ": " odd ",
        " Story ": " story ",
        " Baby ": " baby ",
        " About ": " about ",
        " Pastors": " pastors",
        " Faith": " faith",
        " Volunteer": " volunteer",
        " Suggestions": " suggestions",
    }
    padded = f" {phrase} "
    for old, new in replacements.items():
        padded = padded.replace(old, new)
    return normalize_spacing(padded)


def title_based_description(title: str, post: dict[str, Any]) -> str:
    topic = clean_title_topic(title)
    if not topic:
        return f"Presents {quote_title(title)}."
    if re.match(r"^For Further Reading\b", topic, flags=re.IGNORECASE):
        reading_topic = re.sub(r"^For Further Reading\s*:?\s*", "", topic, flags=re.IGNORECASE).strip(" .,:;-")
        return f"Lists further reading on {lower_first_word(smooth_title_phrase(reading_topic))}."
    if re.match(r"^I Need a Volunteer", topic, flags=re.IGNORECASE):
        return "Requests a blog volunteer."
    if re.match(r"^I Need Some Suggestions", topic, flags=re.IGNORECASE):
        return "Asks readers for suggestions."
    if re.match(r"^My New Podcast", topic, flags=re.IGNORECASE):
        return "Asks readers for questions for the author's new podcast."
    if re.search(r"\bin a nutshell\b", topic, flags=re.IGNORECASE):
        nutshell_topic = re.sub(r"\bin a nutshell\b", "", topic, flags=re.IGNORECASE).strip(" .,:;-")
        return f"Summarizes {lower_first_word(smooth_title_phrase(nutshell_topic))}."
    phrase = question_to_phrase(topic)
    phrase = re.sub(r"\bI\b", "the author", phrase)
    phrase = re.sub(r"\bi\b", "the author", phrase)
    phrase = re.sub(r"\bMe\b", "the author", phrase)
    phrase = re.sub(r"\bmy\b", "the author's", phrase, flags=re.IGNORECASE)
    phrase = re.sub(r"\bWas\b", "was", phrase)
    phrase = re.sub(r"\bAm\b", "am", phrase)
    phrase = re.sub(r"\bHave\b", "have", phrase)
    phrase = re.sub(r"\bYour\b", "your", phrase)
    phrase = smooth_title_phrase(phrase)
    lowered = phrase.lower()
    if lowered.startswith("whether "):
        return f"Considers {phrase}."
    if lowered.startswith("why "):
        return f"Explores {phrase}."
    if lowered.startswith("how "):
        return f"Explains {phrase}."
    if lowered.startswith(("what ", "who ")):
        return f"Explains {phrase}."
    if lowered.startswith(("when ", "where ")):
        return f"Examines {phrase}."
    return f"Examines {lower_first_word(phrase)}."


def admin_description(title: str, body: str) -> str:
    lowered = title.lower()
    if "turns 14" in lowered or "you're invited" in lowered or "you’re invited" in lowered:
        return "Invites readers to a fourteenth-anniversary blog event."
    if "podcast" in lowered and "interview" in lowered:
        about_match = re.search(r"\bAbout\s+(.+)$", title, flags=re.IGNORECASE)
        if about_match:
            topic = smooth_title_phrase(about_match.group(1).strip())
            if normalize_keyword(topic) == "love thy stranger":
                return "Discusses a podcast interview about Love Thy Stranger."
            return f"Discusses a podcast interview about {lower_first_word(topic)}."
        return f"Discusses a podcast interview titled {quote_title(title)}."
    if "interview" in lowered:
        about_match = re.search(r"\bAbout\s+(.+)$", title, flags=re.IGNORECASE)
        if about_match:
            return f"Discusses an interview about {lower_first_word(smooth_title_phrase(about_match.group(1).strip()))}."
        return f"Discusses an interview titled {quote_title(title)}."
    if "podcast" in lowered:
        return f"Discusses a podcast episode titled {quote_title(title)}."
    if lowered.startswith("i need a volunteer"):
        return "Requests a blog volunteer."
    if lowered.startswith("i need some suggestions"):
        return "Asks readers for suggestions."
    if lowered.startswith("my new podcast"):
        return "Asks readers for questions for the author's new podcast."
    if "platinum post" in lowered:
        return f"Features a Platinum member post titled {quote_title(title)}."
    if "guest post" in lowered:
        return f"Features a guest post titled {quote_title(title)}."
    if "q&a" in lowered or "qa" in lowered:
        topic_match = re.search(r"Bart has chosen:\s*([^.;\n]+)", body, flags=re.IGNORECASE)
        if topic_match:
            topic = re.split(r"\s+(?:If you|Prepare|Make sure|Keep your)\b", topic_match.group(1).strip())[0]
            return f"Announces a member Q&A focused on {topic.strip()}."
        return f"Announces a member Q&A titled {quote_title(title)}."
    if "webinar" in lowered:
        return f"Announces a webinar titled {quote_title(title)}."
    if "course" in lowered:
        return f"Announces a course titled {quote_title(title)}."
    if "lecture" in lowered:
        return f"Announces a lecture titled {quote_title(title)}."
    if any(term in lowered for term in ("dinner", "cruise", "trip", "travel", "sailing")):
        return f"Announces a blog event or travel opportunity titled {quote_title(title)}."
    if "opportunity" in lowered:
        return f"Announces a blog opportunity titled {quote_title(title)}."
    if any(term in lowered for term in ("membership", "subscription", "gift")):
        return f"Gives membership or subscription information under the title {quote_title(title)}."
    return f"Presents blog information under the title {quote_title(title)}."


def question_fragment(question: str) -> str:
    value = normalize_spacing(question)
    value = re.sub(r"^I (understand|wonder|am wondering|would like to know|have a question).*?\b(if|whether|why|how|what)\b", r"\2", value, flags=re.IGNORECASE)
    value = re.sub(r"^If\s+", "whether ", value)
    value = value.split("?")[0]
    value = truncate_phrase(value, 95)
    value = value.strip(" .,:;")
    if not value or re.match(r"^(you|i|ok|please|dr\.?|as|and|but)\b", value, flags=re.IGNORECASE):
        return ""
    return lower_first_word(value)


def clean_topic_fragment(value: str) -> str:
    topic = normalize_spacing(value).strip(" .,:;-")
    topic = re.sub(r"^(?:including\s+)?(?:on|about)\s+", "", topic, flags=re.IGNORECASE)
    topic = re.sub(r"\b(?:questions?|answers?|responses?|readers?|mailbag)\b", "", topic, flags=re.IGNORECASE)
    topic = normalize_spacing(topic).strip(" .,:;-")
    if not topic or topic.lower() in {"some important", "some intriguing", "interesting", "good", "really"}:
        return ""
    topic = question_to_phrase(topic)
    topic = re.sub(r"\bWhen\b", "when", topic)
    topic = re.sub(r"\bReally\b", "really", topic)
    topic = re.sub(r"\bBetray\b", "betray", topic)
    topic = re.sub(r"\bwhether Judas really betray Jesus\b", "whether Judas really betrayed Jesus", topic)
    topic = re.sub(r"\bTeach\b", "teach", topic)
    return topic


def reader_topics_from_title(title: str) -> list[str]:
    value = clean_title(title)
    value = re.sub(r"^Why\s+Do\s+Are\s+", "Why Are ", value, flags=re.IGNORECASE)
    if re.match(r"^My New Podcast\b", value, flags=re.IGNORECASE):
        return ["questions for the author's new podcast"]
    value = re.sub(r"^Readers[’']?\s+Mailbag\s*:\s*", "", value, flags=re.IGNORECASE)
    including_match = re.search(r"\((?:including\s+)?on\s+(.+?)\)", value, flags=re.IGNORECASE)
    if including_match:
        value = including_match.group(1)
    about_match = re.search(r"\b(?:questions?|answers?|responses?)\s+(?:on|about)\s+(.+)$", value, flags=re.IGNORECASE)
    if about_match:
        value = about_match.group(1)
    value = re.sub(r"\bAnswers?\s+to\s+Readers[’']?\s+Questions\b.*$", "", value, flags=re.IGNORECASE).strip(" :-")
    value = re.sub(r"\b(Interesting|Good)?\s*Readers[’']?\s*Questions\b.*$", "", value, flags=re.IGNORECASE).strip(" :-")
    value = re.sub(r"\bReaders[’']?\s*Mailbag\b.*$", "", value, flags=re.IGNORECASE).strip(" :-")
    value = re.sub(r"^Weekly\s+Readers\s+Mailbag\b.*$", "", value, flags=re.IGNORECASE).strip(" :-")
    value = re.sub(r"^Question:\s*", "", value, flags=re.IGNORECASE).strip()
    value = re.sub(r"^Some\s+(Intriguing|Important|Interesting)\s+Questions\s+about\s+", "", value, flags=re.IGNORECASE).strip()
    if not value:
        return []
    from_match = re.match(r"^From\s+(.+)$", value, flags=re.IGNORECASE)
    if from_match:
        value = from_match.group(1)
    pieces = re.split(r"\s+\bto\b\s+|\s*;\s*|\?+", value, flags=re.IGNORECASE)
    topics = [clean_topic_fragment(piece) for piece in pieces]
    topics = [topic for topic in topics if topic]
    return topics[:3]


def reader_topics_from_post(post: dict[str, Any]) -> list[str]:
    themes = [
        theme
        for theme in unique_strings(post.get("themes", []))
        if normalize_keyword(theme) != "ignore"
    ]
    return themes[:3]


def reader_question_description(title: str, body: str, post: dict[str, Any]) -> str | None:
    title_topics = reader_topics_from_title(title)
    if title_topics:
        return f"Answers reader questions about {join_phrases(title_topics)}."
    if not re.search(r"(?:^|\s)(?:QUESTION\s*:|Q\s*:)", body, flags=re.IGNORECASE):
        post_topics = reader_topics_from_post(post)
        if post_topics:
            return f"Answers reader questions about {join_phrases(post_topics)}."
        return None
    question_blocks = re.findall(
        r"(?:^|\s)(?:QUESTION\s*:|Q\s*:)\s*(.*?)(?=\s*(?:RESPONSE|ANSWER|QUESTION\s*:|Q\s*:)\b|$)",
        body,
        flags=re.IGNORECASE,
    )
    fragments = [question_fragment(block) for block in question_blocks]
    fragments = [fragment for fragment in fragments if fragment]
    if not fragments:
        post_topics = reader_topics_from_post(post)
        if post_topics:
            return f"Answers reader questions about {join_phrases(post_topics)}."
        return None
    fragments = fragments[:3]
    return f"Answers reader questions about {join_phrases(fragments)}."


def join_phrases(values: list[str]) -> str:
    if len(values) == 1:
        return values[0]
    if len(values) == 2:
        return f"{values[0]} and {values[1]}"
    return f"{', '.join(values[:-1])}, and {values[-1]}"


def fallback_description(title: str, post: dict[str, Any]) -> str:
    themes = [
        theme
        for theme in unique_strings(post.get("themes", []))
        if normalize_keyword(theme) != "ignore"
    ]
    if themes:
        return f"{title_based_description(title, post).rstrip('.')} in connection with {join_phrases(themes[:3])}."
    return f"Presents {quote_title(title)}."


def has_scaffold_start(phrase: str) -> bool:
    lowered = phrase.strip().lower()
    if not lowered:
        return True
    return lowered.startswith((
        "but ",
        "but,",
        "(",
        "a couple of readers",
        "a few days ago",
        "as i ",
        "as i’ve",
        "as i've",
        "here i ",
        "here i'll",
        "here i’ll",
        "here ",
        "here are",
        "here angus",
        "here now ",
        "here we ",
        "here on",
        "blog post",
        "in a post a few days ago",
        "in this article",
        "in an earlier post",
        "in a previous post",
        "in my last post",
        "in recent posts",
        "in reply",
        "in my earlier",
        "in my head",
        "in my ",
        "in my previous",
        "in the previous",
        "in this nutshell series",
        "in this “nutshell” series",
        "it ",
        "it’s",
        "it's",
        "itâ€™s",
        "let me ",
        "not wanting",
        "now i ",
        "now we ",
        "over the past",
        "are you curious",
        "whether you curious",
        "you ",
        "one of ian",
        "one of my",
        "q:",
        "question:",
        "response:",
        "the question",
        "this debate focuses",
        "this is ",
        "this article",
        "this is worthwhile",
        "this is the one",
        "this is my",
        "usually the questions",
    ))


def description_verb(phrase: str) -> str:
    lowered = phrase.lower()
    if lowered.startswith("whether "):
        return "considers"
    if lowered.startswith("why "):
        return "explores"
    if lowered.startswith("how "):
        return "explains"
    if lowered.startswith(("what ", "who ")):
        return "explains"
    if lowered.startswith(("when ", "where ")):
        return "examines"
    if lowered.startswith(("a ", "an ", "one modern explanation", "one common argument", "one possible")):
        return "examines"
    if lowered.startswith(("scholars ", "one of ")):
        return "notes that"
    if lowered.startswith(("persecutions ", "christian persecutions ")):
        return "explains that"
    if lowered.startswith((
        "there are ",
        "there is ",
        "there's ",
        "there’s ",
        "it is ",
        "it was ",
        "it has ",
        "older scholarship ",
        "pagan opponents ",
        "the best place ",
        "in ",
        "on ",
        "as ",
        "for ",
        "to ",
        "this ",
        "these ",
    )):
        return "notes that"
    if any(term in lowered for term in ("argues", "argument", "claim", "thesis")):
        return "examines"
    if any(term in lowered for term in ("shows", "showing", "explains")):
        return "explains"
    if re.search(r"\b(is|are|was|were|has|have|had|seems|appears)\b", lowered.split(",", 1)[0]):
        return "notes that"
    return "discusses"


def apply_description_verb(verb: str, phrase: str) -> str:
    return f"{verb[:1].upper() + verb[1:]} {phrase}."


def sentence_terms(value: str) -> set[str]:
    return {
        word
        for word in re.findall(r"[A-Za-z0-9]+", value.lower())
        if len(word) > 3 and word not in TITLE_STOP_WORDS
    }


def sentence_overlap(left: str, right: str) -> float:
    left_terms = sentence_terms(left)
    right_terms = sentence_terms(right)
    if not left_terms or not right_terms:
        return 0.0
    return len(left_terms & right_terms) / min(len(left_terms), len(right_terms))


def sentence_end_count(value: str) -> int:
    return len(re.findall(r"[.!?](?:\s|$)", value))


def normalize_sentence(value: str, max_length: int = MAX_SENTENCE_LENGTH) -> str:
    sentence = normalize_spacing(value).strip()
    if not sentence:
        return ""
    sentence = re.sub(r"\s+([,.;:!?])", r"\1", sentence)
    sentence = re.sub(r"\.{2,}$", ".", sentence)
    if not sentence.endswith((".", "?", "!")):
        sentence = sentence.rstrip(" .") + "."
    if len(sentence) > max_length:
        sentence = truncate_phrase(sentence.rstrip("."), max_length - 1) + "."
    return sentence[:1].upper() + sentence[1:]


def remove_disallowed_pronouns(value: str, title: str) -> str:
    replacement = "the subject"
    if re.search(r"\bJesus\b", title):
        replacement = "Jesus"
    elif re.search(r"\bPaul\b", title):
        replacement = "Paul"
    elif re.search(r"\bPeter\b", title):
        replacement = "Peter"
    elif re.search(r"\bGod\b", title):
        replacement = "God"
    value = re.sub(r"(?<!Part )\bI\b", "the author", value)
    value = re.sub(r"\b[Hh]e\b", replacement, value)
    return value


def bad_description_fragment(value: str) -> bool:
    lowered = value.strip().lower()
    return (
        not lowered
        or has_scaffold_start(lowered)
        or re.search(r"\b(?:QUESTION|RESPONSE|ANSWER)\s*:", value, flags=re.IGNORECASE) is not None
        or re.search(
            r"\bI\s+(?:set out|was|have|had|am|will|would|could|think|argued|argue|asked|mentioned|said|try|gave|indicated|talked|received|want|need|continue|provide|pick|present|describe|discuss|ended)\b",
            value,
            flags=re.IGNORECASE,
        ) is not None
        or re.search(r"\b(my|our)\s+(book|post|previous|earlier|students|view|favorite)\b", value, flags=re.IGNORECASE) is not None
    )


def sentence_to_description(sentence: str, title: str, post: dict[str, Any]) -> str:
    phrase = strip_first_person_frame(sentence)
    phrase = question_to_phrase(phrase)
    phrase = phrase.replace("a question raised by a reader", "a reader question")
    if starts_with_first_person(phrase) or bad_description_fragment(phrase):
        return ""
    description = description_from_phrase(phrase, title, post)
    return normalize_sentence(description)


def support_sentence_from_body(
    sentences: list[str],
    title: str,
    post: dict[str, Any],
    first_sentence: str,
    used_sentence: str,
) -> str:
    title_terms = title_keywords(title)
    candidates: list[tuple[int, str]] = []
    used_key = normalize_spacing(used_sentence).casefold()
    first_terms = sentence_terms(first_sentence)

    for index, sentence in enumerate(sentences[:28]):
        if normalize_spacing(sentence).casefold() == used_key:
            continue
        if is_weak_sentence(sentence):
            continue
        description = sentence_to_description(sentence, title, post)
        if not description:
            continue
        if sentence_overlap(description, first_sentence) > 0.72:
            continue
        terms = sentence_terms(description)
        score = max(0, 20 - index)
        score += 3 * len(terms & title_terms)
        score += len(terms & first_terms)
        if 70 <= len(description) <= 220:
            score += 6
        if description.startswith(("Notes that", "Discusses")):
            score -= 2
        candidates.append((score, description))

    if not candidates:
        return ""
    candidates.sort(key=lambda item: item[0], reverse=True)
    return candidates[0][1]


def theme_support_sentence(post: dict[str, Any]) -> str:
    themes = [
        theme
        for theme in unique_strings(post.get("themes", []))
        if normalize_keyword(theme) != "ignore"
    ]
    if not themes:
        return "It is mainly a blog notice rather than a topical essay."
    if len(themes) == 1:
        return f"The main topic is {themes[0]}."
    return f"The discussion centers on {join_phrases(themes[:3])}."


TOPIC_LABELS = {
    "acts": "Acts",
    "ancient literacy": "ancient literacy",
    "apocryphal acts": "Apocryphal Acts",
    "birth narrative": "birth narrative",
    "capitalism": "capitalism",
    "christianity": "Christianity",
    "christ as an angel": "Christ as an angel",
    "christology": "christology",
    "conversion": "conversion",
    "edessa": "Edessa",
    "early christianity": "early Christianity",
    "ephesians": "Ephesians",
    "eternal punishment": "eternal punishment",
    "existence of god": "the existence of God",
    "free will": "free will",
    "general epistles": "General Epistles",
    "grace": "grace",
    "greco roman religion": "Greco-Roman religion",
    "gospel of judas": "Gospel of Judas",
    "gospel of thomas": "Gospel of Thomas",
    "gospels": "gospels",
    "gnosticism and proto orthodoxy": "Gnosticism and proto-orthodoxy",
    "goodness": "goodness",
    "health care": "health care",
    "hell": "hell",
    "how jesus became god": "How Jesus Became God",
    "john the baptist": "John the Baptist",
    "miracle claims and apologetics": "miracle claims and apologetics",
    "miracles and conversion": "miracles and conversion",
    "miracles in acts": "miracles in Acts",
    "martyrdom": "martyrdom",
    "miracles": "miracles",
    "non canonical gospels": "non-canonical gospels",
    "petrine forgeries": "Petrine forgeries",
    "predestination": "predestination",
    "prophets": "prophets",
    "proto gospel of james": "Proto-Gospel of James",
    "proto orthodoxy": "proto-orthodoxy",
    "problem of evil": "the problem of evil",
    "problem of suffering": "the problem of suffering",
    "problem of suffering general": "the problem of suffering",
    "protestant ethic": "the Protestant ethic",
    "resurrection": "resurrection",
    "resurrection of jesus": "the resurrection of Jesus",
    "roman empire": "the Roman Empire",
    "roman world": "the Roman world",
    "rise of christianity": "the rise of Christianity",
    "salvation": "salvation",
    "son of man": "Son of Man",
    "suffering": "suffering",
    "textual criticism": "textual criticism",
    "textual criticism methods": "textual criticism methods",
    "triumph of christianity": "The Triumph of Christianity",
    "writing and research process": "writing and research process",
    "writing books": "writing books",
    "dissertation writing": "dissertation writing",
    "writing method": "writing method",
}

PROPER_TOPIC_WORDS = {
    "Acts",
    "Amos",
    "Augustine",
    "Calvin",
    "Christianity",
    "Corinthians",
    "Daniel",
    "David",
    "Deuteronomy",
    "Ecclesiastes",
    "Edessa",
    "Ephesians",
    "Eusebius",
    "Exodus",
    "Ezekiel",
    "Galatians",
    "Genesis",
    "Greco-Roman",
    "Gospel",
    "Gospels",
    "Hebrews",
    "Hurtado",
    "Isaiah",
    "James",
    "Jeremiah",
    "Jesus",
    "Job",
    "John",
    "Josephus",
    "Judas",
    "Hammadi",
    "Luke",
    "Mark",
    "Mary",
    "Matthew",
    "Metzger",
    "Moses",
    "Nag",
    "Paul",
    "Peter",
    "Philip",
    "Philippians",
    "Protestant",
    "Revelation",
    "Romans",
    "Thaddaeus",
    "Thessalonians",
    "Weber",
}


def topic_label(topic: str) -> str:
    topic = re.sub(r"\s*\(General\)\s*", "", topic).strip()
    key = normalize_keyword(topic)
    if key in TOPIC_LABELS:
        return TOPIC_LABELS[key]
    if topic.isupper() and len(topic) <= 8:
        return topic
    words = re.split(r"(\s+)", topic)
    formatted: list[str] = []
    for word in words:
        bare = word.strip(".,;:!?()[]")
        if not bare or word.isspace():
            formatted.append(word)
        elif bare in PROPER_TOPIC_WORDS or bare.isupper():
            formatted.append(word)
        else:
            formatted.append(word.lower())
    return "".join(formatted)


def support_topic_key(topic: str) -> str:
    topic = re.sub(r"\s*\(General\)\s*", "", topic).strip()
    return normalize_keyword(topic)


def support_topics(post: dict[str, Any], limit: int = 4) -> list[str]:
    themes = unique_strings(post.get("themes", []))
    theme_keys = {normalize_keyword(theme) for theme in themes}
    skip = {
        "",
        "ignore",
        "post",
        "posts",
        "blog",
        "article",
        "articles",
        "nrsv",
        "nrsvue",
    }
    topics: list[str] = []
    seen: set[str] = set()
    for source in (themes, post.get("secondaryKeywords", [])):
        for topic in unique_strings(source):
            key = support_topic_key(topic)
            if key in skip or key in seen:
                continue
            seen.add(key)
            topics.append(topic_label(topic))
            if len(topics) >= limit:
                return topics
    return topics


def metadata_support_sentence(post: dict[str, Any], primary: str) -> str:
    lowered = primary.lower()
    if normalize_keyword(primary).startswith("announces"):
        return "It gives readers the practical details for the announcement."
    if lowered.startswith(("features", "presents blog information", "gives membership")):
        return "It is mainly a site notice rather than a topical essay."
    if not [
        theme
        for theme in unique_strings(post.get("themes", []))
        if normalize_keyword(theme) != "ignore"
    ]:
        return "It is mainly a site notice rather than a topical essay."

    topics = support_topics(post)
    if not topics:
        return theme_support_sentence(post)
    if lowered.startswith("answers reader questions"):
        return f"The questions involve {join_phrases(topics)}."
    if lowered.startswith(("lists further reading", "summarizes")):
        return f"The material is organized around {join_phrases(topics)}."
    return f"Key topics include {join_phrases(topics)}."


def make_two_sentence_description(
    primary: str,
    title: str,
    post: dict[str, Any],
    sentences: list[str],
    used_sentence: str = "",
) -> str:
    first = normalize_sentence(ensure_description_shape(primary, title, post), 260)
    if not first:
        first = normalize_sentence(fallback_description(title, post), 260)

    if sentence_end_count(first) > 1:
        parts = [part.strip() for part in re.split(r"(?<=[.!?])\s+", first) if part.strip()]
        first = normalize_sentence(parts[0], 260)

    second = metadata_support_sentence(post, first)
    if not second or sentence_overlap(first, second) > 0.72:
        second = support_sentence_from_body(sentences, title, post, first, used_sentence)
    if not second or sentence_overlap(first, second) > 0.72:
        second = theme_support_sentence(post)

    second = normalize_sentence(second, 240)
    description = normalize_spacing(f"{first} {second}")
    if len(description) > MAX_DESCRIPTION_LENGTH:
        second_budget = max(90, MAX_DESCRIPTION_LENGTH - len(first) - 2)
        description = normalize_spacing(f"{first} {normalize_sentence(second, second_budget)}")
    return description


def description_from_phrase(phrase: str, title: str, post: dict[str, Any]) -> str:
    value = truncate_phrase(smooth_title_phrase(lower_first_word(phrase)))
    value = re.sub(r"\bwhy it did take\b", "why it took", value, flags=re.IGNORECASE)
    value = re.sub(r"\bwhy it does take\b", "why it takes", value, flags=re.IGNORECASE)
    if not value:
        return fallback_description(title, post)
    if (
        has_scaffold_start(value)
        or re.search(r"\b(?:QUESTION|RESPONSE|ANSWER)\s*:", value, flags=re.IGNORECASE)
        or re.search(r"\bI\s+(?:set out|was|have|had|am|will|would|could|think|argued|argue|asked|mentioned|said|try|gave|indicated|talked|received|want|need|continue|provide|pick|present|describe|discuss)\b", value, flags=re.IGNORECASE)
        or re.search(r"\b(my|our)\s+(book|post|previous|earlier|students|view|favorite)\b", value, flags=re.IGNORECASE)
    ):
        return title_based_description(title, post)
    return apply_description_verb(description_verb(value), value)


def ensure_description_shape(description: str, title: str, post: dict[str, Any]) -> str:
    value = normalize_spacing(description).strip()
    value = re.sub(r"^This post responds to\s+", "Answers ", value)
    value = re.sub(r"^This post notes that\s+", "Notes that ", value)
    legacy_match = re.match(
        r"^This post (gives|announces|examines|explains|explores|considers|discusses|presents)\s+(.+)$",
        value,
        flags=re.IGNORECASE,
    )
    if legacy_match:
        verb = legacy_match.group(1)
        value = f"{verb[:1].upper() + verb[1:]} {legacy_match.group(2)}"
    if not value:
        value = fallback_description(title, post)
    value = remove_disallowed_pronouns(value, title)
    value = value[:1].upper() + value[1:]
    if not value.endswith("."):
        value = value.rstrip(" .") + "."
    if len(value) > MAX_DESCRIPTION_LENGTH:
        match = re.match(
            r"^((?:Answers|Notes that|Gives|Announces|Examines|Explains|Explores|Considers|Discusses|Presents|Surveys|Features)\s+)",
            value,
        )
        prefix = match.group(1) if match else ""
        phrase = value[len(prefix):].rstrip(".") if value.startswith(prefix) else value
        max_phrase_length = MAX_DESCRIPTION_LENGTH - len(prefix) - 1 if prefix else MAX_DESCRIPTION_LENGTH - 1
        value = prefix + truncate_phrase(phrase, max_phrase_length) + "."
    return value


def build_description(post: dict[str, Any], raw_post: dict[str, Any] | None = None) -> str:
    title = clean_title(post.get("title"))
    url = clean_string(post.get("url"))

    raw = raw_post or {}
    body = clean_text(raw.get("text", ""), title, clean_string(raw.get("dateText", post.get("dateText", ""))))
    sentences = split_sentences(body)
    if url in APPROVED_DESCRIPTIONS_BY_URL:
        return ensure_description_shape(APPROVED_DESCRIPTIONS_BY_URL[url], title, post)

    lowered_title = title.lower()
    themes = [
        theme
        for theme in unique_strings(post.get("themes", []))
        if normalize_keyword(theme) != "ignore"
    ]

    if not themes or any(term in lowered_title for term in ADMIN_TITLE_TERMS):
        return ensure_description_shape(admin_description(title, body), title, post)

    question_description = reader_question_description(title, body, post)
    if question_description and any(term in lowered_title for term in ("question", "mailbag", "q&a", "readers")):
        return ensure_description_shape(question_description, title, post)

    key_sentence = select_key_sentence(sentences, title)
    phrase = strip_first_person_frame(key_sentence)
    phrase = question_to_phrase(phrase)
    phrase = phrase.replace("a question raised by a reader", "a reader question")
    if starts_with_first_person(phrase):
        return ensure_description_shape(fallback_description(title, post), title, post)
    if not phrase:
        return ensure_description_shape(fallback_description(title, post), title, post)
    return ensure_description_shape(description_from_phrase(phrase, title, post), title, post)


def add_descriptions(
    posts: list[dict[str, Any]],
    raw_posts: dict[str, dict[str, Any]],
) -> tuple[list[OrderedDict[str, Any]], list[str]]:
    missing_raw_urls: list[str] = []
    updated_posts: list[OrderedDict[str, Any]] = []

    for post in posts:
        url = clean_string(post.get("url"))
        raw_post = raw_posts.get(url)
        if raw_post is None:
            missing_raw_urls.append(url)

        updated: OrderedDict[str, Any] = OrderedDict()
        inserted_description = False
        for key, value in post.items():
            if key == "description":
                continue
            updated[key] = value
            if key == "author":
                updated["description"] = build_description(post, raw_post)
                inserted_description = True
        if not inserted_description:
            updated["description"] = build_description(post, raw_post)
        updated_posts.append(updated)

    return updated_posts, missing_raw_urls


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Generate conservative one-sentence hover descriptions from raw post text."
    )
    parser.add_argument("--keywords", type=Path, default=DEFAULT_KEYWORDS_PATH)
    parser.add_argument("--raw-posts", type=Path, default=DEFAULT_RAW_POSTS_PATH)
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--sample", type=int, default=0)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    posts = json.loads(args.keywords.read_text(encoding="utf-8"))
    raw_posts = load_raw_posts(args.raw_posts)
    updated_posts, missing_raw_urls = add_descriptions(posts, raw_posts)

    if args.sample:
        for post in updated_posts[: args.sample]:
            print(post["title"])
            print(post["description"])
            print()

    if missing_raw_urls:
        print(f"Warning: {len(missing_raw_urls)} keyword posts were not found in the raw corpus.")

    if not args.dry_run:
        args.keywords.write_text(
            json.dumps(updated_posts, ensure_ascii=False, indent=2) + "\n",
            encoding="utf-8",
            newline="\n",
        )
        print(f"Updated {args.keywords} with {len(updated_posts):,} descriptions.")
    else:
        print(f"Dry run generated {len(updated_posts):,} descriptions.")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
