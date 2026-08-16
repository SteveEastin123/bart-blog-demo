"""Add the August 10-16, 2026 downloads to the canonical search index."""

from __future__ import annotations

import add_posts_2026_08_04_2026_08_09 as importer


importer.ASSIGNMENTS = {
    "50408": {
        "description": (
            "Announces a September 1 dinner in New York City for blog members "
            "and provides attendance details."
        ),
        "topics": ["Ignore"],
        "secondaryKeywords": [],
    },
    "50367": {
        "description": (
            "Interprets Jesus's disruption of the Jerusalem Temple as a symbolic "
            "enactment of its coming destruction rather than an attack on Judaism "
            "or a program of social reform. The action reflects Jesus's apocalyptic "
            "expectation that God would soon judge the existing order."
        ),
        "topics": ["Apocalyptic Jesus", "Jesus' Passion Narratives"],
        "secondaryKeywords": [
            "Cleansing of the Temple",
            "Jerusalem Temple",
            "Temple Destruction",
            "Sacrifice",
            "Passover",
            "Son of Man",
            "Final Judgment",
            "Kingdom of God",
        ],
    },
    "50349": {
        "description": (
            "Answers reader questions about suffering as an objection to an "
            "all-loving and all-powerful God, the historical evaluation of miracle "
            "claims, and the rapid production of written Gospels after Paul's death."
        ),
        "topics": [
            "Problem of Evil and Suffering",
            "Historical Study of Miracles",
            "Dating the Gospels",
        ],
        "secondaryKeywords": [
            "David Hume",
            "Miracle Claims",
            "Oral Tradition",
            "Canonical Gospels",
            "Q Source",
            "Ignatius",
            "Polycarp",
        ],
    },
    "50251": {
        "description": (
            "Surveys early Christian evidence that some writers regarded Peter and "
            "Cephas as different people. These traditions challenge their customary "
            "identification despite John 1:42."
        ),
        "topics": ["Peter the Apostle"],
        "secondaryKeywords": [
            "Cephas",
            "Paul",
            "Galatians",
            "Gospel of John",
            "Early Christian Writings",
            "Church Fathers",
        ],
    },
    "50382": {
        "description": (
            "Interprets Matthew's temple-tax story as portraying Jesus as relatively "
            "indifferent to taxes, political systems, and economic reform unless they "
            "conflict with God's will. The coming Kingdom of God, rather than changing "
            "earthly institutions, remains the central concern."
        ),
        "topics": ["Jesus' Teachings", "Gospel of Matthew"],
        "secondaryKeywords": [
            "Taxation",
            "Jerusalem Temple",
            "Kingdom of God",
            "Render unto Caesar",
            "Temple Authorities",
            "Roman Empire",
            "Peter the Apostle",
        ],
    },
    "50385": {
        "description": (
            "Argues that Paul's separate references to Peter and Cephas in Galatians "
            "make better sense if Paul regarded them as different people. The post "
            "also evaluates and rejects explanations that treat the change in names "
            "as a quotation from an earlier agreement."
        ),
        "topics": ["Peter the Apostle", "Paul and His Opponents"],
        "secondaryKeywords": ["Cephas", "Paul", "Galatians"],
    },
}


if __name__ == "__main__":
    importer.main()
