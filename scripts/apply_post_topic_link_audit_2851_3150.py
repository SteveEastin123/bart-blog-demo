"""Apply approved post-topic audit decisions for audit sequence 2851-3150."""

import apply_post_topic_link_audit_3151_3400 as audit


audit.START_SEQUENCE = 2851
audit.END_SEQUENCE = 3150
audit.EXPECTED_DECISIONS = 36


if __name__ == "__main__":
    audit.main()
